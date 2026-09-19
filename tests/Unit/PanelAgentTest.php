<?php
namespace Tests\Unit;

use App\Services\PanelAgentService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelAgentTest extends TestCase
{
    use \Tests\Concerns\FakesAgentTransport;

    protected function setUp(): void { parent::setUp(); $this->fakeAgentTransport(); }
    public function test_agent_and_entry_routes_require_admin_authentication(): void
    {
        foreach (['agent/settings', 'agent/chat', 'agent/confirm', 'agent/start', 'agent/run', 'agent/run-cancel', 'agent/conversation', 'subscription-entry/preview', 'subscription-entry/apply', 'model-relay/status'] as $suffix) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), '/' . $suffix));
            $this->assertNotNull($route);
            $this->assertContains('admin', $route->gatherMiddleware());
            $url = '/' . $route->uri();
            if (in_array('GET', $route->methods(), true)) $this->getJson($url)->assertStatus(403);
            else $this->postJson($url, ['user' => ['id' => 1, 'is_admin' => 1]])->assertStatus(403);
        }
    }
    public function test_only_whitelisted_application_tools_are_available(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new PanelAgentService())->executeTool('execute_sql', ['sql' => 'select 1'], ['nodes_list'], Request::create('/'));
    }

    public function test_model_tool_loop_redacts_server_secrets(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('info')->once();
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn(['enabled' => true, 'profiles' => ['grok' => ['enabled' => true, 'api_key' => 'test', 'base_url' => 'https://model.example/v1']], 'tools' => ['nodes_list']]);
        $servers = \Mockery::mock(ServerService::class);
        $servers->shouldReceive('getNodeStatusDetails')->once()->andReturn([['id' => 1, 'parent_id' => 29, 'type' => 'v2node', 'protocol' => 'vless', 'show' => false, 'name' => 'sample', 'host' => '192.0.2.1', 'install_command' => 'SECRET', 'token' => 'SECRET']]);
        $this->app->instance(ServerService::class, $servers);
        Http::fake(['model.example/*' => Http::sequence()
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'nodes_list', 'arguments' => '{}']]]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '查询完成']]]])]);
        $result = $agent->chat('查看节点', Request::create('/', 'POST', ['user' => ['id' => 1]]));
        $this->assertSame('查询完成', $result['reply']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'SECRET'));
        Http::assertSent(function ($r) {
            foreach ($r['messages'] as $m) if ($m['role'] === 'tool') {
                $nodes = json_decode($m['content'], true);
                return $nodes[0]['parent_id'] === 29 && $nodes[0]['show'] === false && $nodes[0]['type'] === 'v2node';
            }
            return false;
        });
    }

    public function test_stored_api_key_is_encrypted_and_not_returned(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        $path = sys_get_temp_dir() . '/agent-test-' . uniqid() . '.json';
        $agent = new class($path) extends PanelAgentService {
            private string $file;
            public function __construct($file) { $this->file = $file; }
            protected function path(): string { return $this->file; }
        };
        try {
            $out = $agent->save(['enabled' => true, 'base_url' => 'https://model.example/v1', 'model' => 'test', 'api_key' => 'PRIVATE-KEY', 'tools' => ['nodes_list']]);
            $this->assertArrayNotHasKey('api_key', $out);
            $this->assertStringNotContainsString('PRIVATE-KEY', file_get_contents($path));
            $this->assertSame('PRIVATE-KEY', $agent->settings(true)['api_key']);
        } finally { if (is_file($path)) unlink($path); }
    }

    public function test_bad_key_is_repaired_using_tool_feedback_without_writing(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('info')->once();
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn(['enabled' => true, 'profiles' => ['grok' => ['enabled' => true, 'api_key' => 'test', 'base_url' => 'https://model.example/v1']], 'tools' => ['preview_node_rename']]);
        $agent->shouldReceive('executeTool')->once()->with('preview_node_rename', ['changes' => [['key' => '122', 'value' => 'test']]], ['preview_node_rename'], \Mockery::any())->andThrow(new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'Missing node'));
        $agent->shouldReceive('executeTool')->once()->with('preview_node_rename', ['changes' => [['key' => 'v2node:122', 'value' => 'test']]], ['preview_node_rename'], \Mockery::any())->andReturn(['confirmation' => ['rows' => [['key' => 'v2node:122', 'after' => 'test']]]]);
        $sequence = Http::sequence();
        foreach (['122', 'v2node:122'] as $key) $sequence->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_' . $key, 'type' => 'function', 'function' => ['name' => 'preview_node_rename', 'arguments' => json_encode(['changes' => [['key' => $key, 'value' => 'test']]])]]]]]]]);
        Http::fake(['model.example/*' => $sequence]);
        $result = $agent->chat('test', Request::create('/'));
        $this->assertCount(1, $result['actions']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'invalid_tool_arguments'));
    }

    public function test_argument_repairs_are_bounded_and_permission_errors_never_retry(): void
    {
        foreach ([422 => 3, 403 => 1] as $status => $count) {
            $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
            $agent->shouldReceive('settings')->with(true)->andReturn(['enabled' => true, 'profiles' => ['grok' => ['enabled' => true, 'api_key' => 'test', 'base_url' => 'https://model.example/v1']], 'tools' => ['nodes_list']]);
            $agent->shouldReceive('executeTool')->times($count)->andThrow(new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'test'));
            Http::fake(['model.example/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'nodes_list', 'arguments' => '{}']]]]]]])]);
            try { $agent->chat('test', Request::create('/')); $this->fail('Expected failure'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
        }
    }

    public function test_numeric_node_key_is_rejected_before_preview(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new PanelAgentService())->executeTool('preview_node_rename', ['changes' => [['key' => '122', 'value' => 'test']]], ['preview_node_rename'], Request::create('/'));
    }

    public function test_queries_can_continue_past_three_rounds(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn(['enabled'=>true,'profiles'=>['grok'=>['enabled'=>true,'api_key'=>'test','base_url'=>'https://model.example/v1']],'tools'=>['nodes_list']]);
        $agent->shouldReceive('executeTool')->times(4)->andReturn([['key'=>'v2node:1','name'=>'test']]);
        $sequence=Http::sequence();
        for($i=0;$i<4;$i++) $sequence->push(['choices'=>[['message'=>['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'call_'.$i,'type'=>'function','function'=>['name'=>'nodes_list','arguments'=>json_encode(['test_query'=>$i])]]]]]]]);
        $sequence->push(['choices'=>[['message'=>['role'=>'assistant','content'=>'已查完，请确认入口端口。']]]]);
        Http::fake(['model.example/*'=>$sequence]);
        $result=$agent->chat('test',Request::create('/'));
        $this->assertSame('已查完，请确认入口端口。',$result['reply']);
        Http::assertSentCount(5);
    }

    public function test_budget_end_uses_verified_receipt_not_another_model_reply(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $agent=\Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn(['enabled'=>true,'profiles'=>['grok'=>['enabled'=>true,'api_key'=>'test','base_url'=>'https://model.example/v1']],'tools'=>['nodes_list']]);
        $agent->shouldReceive('executeTool')->once()->andReturn([['key'=>'v2node:1','name'=>'verified-node']]);
        $sequence=Http::sequence();
        for($i=0;$i<12;$i++)$sequence->push(['choices'=>[['message'=>['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'call_'.$i,'type'=>'function','function'=>['name'=>'nodes_list','arguments'=>'{}']]]]]]]);
        Http::fake(['model.example/*'=>$sequence]);
        $result=$agent->chat('test',Request::create('/'));
        $this->assertStringContainsString('未生成可批准的变更',$result['reply']);
        $this->assertStringContainsString('节点查询',$result['reply']);
        Http::assertSentCount(3);
        $this->assertSame([],$result['actions']);
    }
}
