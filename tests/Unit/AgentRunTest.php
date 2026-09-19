<?php

namespace Tests\Unit;

use App\Jobs\PanelAgentRunJob;
use App\Services\AgentRunService;
use App\Services\PanelAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentRunTest extends TestCase
{
    private AgentRunService $runs;
    private PanelAgentService $agent;
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'app.key' => 'base64:' . base64_encode(str_repeat('r', 32))]);
        Cache::flush(); Queue::fake();
        $this->runs = new AgentRunService();
        $this->agent = new class extends PanelAgentService {
            public array $seenContext = [];
            public function settings(bool $secret = false): array { return ['enabled' => true, 'tools' => [], 'profiles' => ['grok' => ['enabled' => true, 'base_url' => 'https://example.test/v1', 'api_key' => 'secret']]]; }
            public function chat(string $prompt, Request $request, ?callable $observe = null, array $context = []): array {
                $this->seenContext = $context;
                $observe('model', ['model' => 'grok-4.6', 'label' => '连接模型']);
                $observe('reasoning', []); $observe('text', ['text' => '你好']);
                return ['reply' => '你好', 'model' => 'grok-4.6', 'actions' => []];
            }
        };
        $this->app->instance(PanelAgentService::class, $this->agent);
    }
    private function start(string $message = '测试', ?string $conversation = null): array
    {
        return $this->runs->start(1, $message, 'grok', (string) Str::uuid(), $conversation);
    }
    public function test_enqueue_is_short_idempotent_and_admin_scoped(): void
    {
        $run = $this->start('PRIVATE PROMPT');
        $again = $this->runs->start(1, 'PRIVATE PROMPT', 'grok', $run['id'], null);
        $this->assertSame($run['id'], $again['id']);
        $this->assertSame('queued', $run['status']);
        Queue::assertPushed(PanelAgentRunJob::class, 1);
        $this->assertStringNotContainsString('PRIVATE PROMPT', Cache::get('panel-agent:1:run:' . $run['id']));
        $this->assertArrayNotHasKey('context_messages', $run);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->runs->snapshot(2, $run['id']);
    }
    public function test_completed_turn_is_restored_and_carried_to_next_turn(): void
    {
        $one = $this->start('记住这次讨论的节点是香港01'); $this->runs->perform(1, $one['id'], $this->agent);
        $view = $this->runs->conversation(1, $one['conversation_id']);
        $this->assertSame('你好', $view['turns'][0]['reply']);
        $this->assertSame(1, $view['context']['turns']);
        $two = $this->start('刚才哪个节点？', $one['conversation_id']); $this->runs->perform(1, $two['id'], $this->agent);
        $this->assertSame('记住这次讨论的节点是香港01', $this->agent->seenContext[0]['content']);
        $this->assertSame('你好', $this->agent->seenContext[1]['content']);
    }
    public function test_cancelling_queued_run_prevents_execution_and_allows_new_turn(): void
    {
        $run = $this->start(); $cancelled = $this->runs->cancel(1, $run['id']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->runs->perform(1, $run['id'], $this->agent);
        $this->assertSame('', $this->runs->snapshot(1, $run['id'])['reply']);
        $this->assertSame(0, $this->runs->conversation(1, $run['conversation_id'])['context']['turns']);
        $this->assertSame('queued', $this->start('下一条', $run['conversation_id'])['status']);
    }
    public function test_cancellation_during_stream_stays_cancelled(): void
    {
        $run = $this->start(); $runs = $this->runs;
        $agent = \Mockery::mock(PanelAgentService::class);
        $agent->shouldReceive('chat')->once()->andReturnUsing(function ($prompt, $request, $emit) use ($runs, $run) {
            $emit('text', ['text' => '部分回答']); $runs->cancel(1, $run['id']);
            $emit('text', ['text' => '不应继续']);
        });
        $this->runs->perform(1, $run['id'], $agent);
        $result = $this->runs->snapshot(1, $run['id']);
        $this->assertSame('cancelled', $result['status']);
        $this->assertStringNotContainsString('不应继续', $result['reply']);
    }
    public function test_context_budget_excludes_failed_turns_and_approval_tokens(): void
    {
        $turns = [];
        for ($i = 0; $i < 12; $i++) $turns[] = ['status' => 'complete', 'prompt' => 'question ' . $i, 'reply' => str_repeat('answer', 1200), 'actions' => []];
        $turns[] = ['status' => 'failed', 'prompt' => 'FAILED PROMPT', 'reply' => '', 'actions' => []];
        $turns[] = ['status' => 'complete', 'prompt' => 'preview', 'reply' => '待确认', 'actions' => [['approvals' => [['token' => 'SECRET_APPROVAL']], 'rows' => [['key' => 'v2node:1', 'name' => 'one', 'after' => 'two']]]]];
        $ctx = $this->runs->context(['turns' => $turns]); $json = json_encode($ctx['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertLessThanOrEqual(32000, strlen($json));
        $this->assertLessThanOrEqual(8, $ctx['meta']['turns']);
        $this->assertGreaterThan(0, $ctx['meta']['omitted']);
        $this->assertStringNotContainsString('SECRET_APPROVAL', $json);
        $this->assertStringNotContainsString('FAILED PROMPT', $json);
        $this->assertStringContainsString('v2node:1', $json);
    }

    public function test_long_queued_tasks_are_not_failed_by_a_generation_time_limit(): void
    {
        $run = $this->start();
        $key = 'panel-agent:1:run:' . $run['id'];
        $stored = json_decode(\Illuminate\Support\Facades\Crypt::decryptString(Cache::get($key)), true);
        $stored['created_at'] = $stored['updated_at'] = time() - 900;
        Cache::put($key, \Illuminate\Support\Facades\Crypt::encryptString(json_encode($stored)), AgentRunService::TTL);
        $this->assertSame('queued', $this->runs->snapshot(1, $run['id'])['status']);
        $renewals = 0;
        $this->runs->perform(1, $run['id'], $this->agent, function () use (&$renewals) { $renewals++; });
        $this->assertSame(1, $renewals);
        $this->assertSame('complete', $this->runs->snapshot(1, $run['id'])['status']);
        $this->assertSame(0, (new PanelAgentRunJob(1, $run['id']))->timeout);
    }

    public function test_worker_renews_its_exact_redis_reservation(): void
    {
        \Illuminate\Support\Facades\Schema::create('v2_user', function ($table) { $table->increments('id'); $table->boolean('is_admin'); $table->boolean('banned'); });
        \Illuminate\Support\Facades\DB::table('v2_user')->insert(['id' => 1, 'is_admin' => true, 'banned' => false]);
        $connection = \Mockery::mock();
        $connection->shouldReceive('eval')->once()->withArgs(function ($script, $keys, $key, $reserved, $expires) {
            return str_contains($script, 'ZSCORE') && $keys === 1 && $key === 'queues:panel_agent:reserved' && $reserved === 'fixture-reservation'
                && $expires >= time() + 238 && $expires <= time() + 241;
        })->andReturn(1);
        $queue = \Mockery::mock(\Illuminate\Queue\RedisQueue::class);
        $queue->shouldReceive('getConnection')->once()->andReturn($connection);
        $queue->shouldReceive('getQueue')->with('panel_agent')->once()->andReturn('queues:panel_agent');
        $job = \Mockery::mock(\Illuminate\Queue\Jobs\RedisJob::class);
        $job->shouldReceive('getRedisQueue')->once()->andReturn($queue);
        $job->shouldReceive('getQueue')->once()->andReturn('panel_agent');
        $job->shouldReceive('getReservedJob')->once()->andReturn('fixture-reservation');
        $run = $this->start(); $task = new PanelAgentRunJob(1, $run['id']); $task->setJob($job);
        $task->handle($this->runs, $this->agent);
        $this->assertSame('complete', $this->runs->snapshot(1, $run['id'])['status']);
    }

    public function test_audit_log_redacts_agent_prompts_without_changing_other_forms(): void
    {
        $handler = new \App\Logging\MysqlLoggerHandler();
        $method = new \ReflectionMethod($handler, 'sanitizeRequestData'); $method->setAccessible(true);
        foreach (['start', 'chat'] as $endpoint) {
            $data = $method->invoke($handler, Request::create('/api/v2/private/agent/' . $endpoint, 'POST', ['message' => 'private prompt', 'client_id' => 'public id']));
            $this->assertSame('[FILTERED]', $data['message']); $this->assertSame('public id', $data['client_id']);
        }
        $data = $method->invoke($handler, Request::create('/api/v2/private/ticket/reply', 'POST', ['message' => 'ticket response']));
        $this->assertSame('ticket response', $data['message']);
    }
}
