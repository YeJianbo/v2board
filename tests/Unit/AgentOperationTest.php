<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Admin\AgentOperationController;
use App\Models\ServerMetadata;
use App\Models\ServerV2node;
use App\Services\AgentOperationService;
use App\Services\NodePresentationService;
use App\Services\PanelAgentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AgentOperationTest extends NodePresentationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        \Illuminate\Support\Facades\Log::spy();
        require_once database_path('migrations/2026_09_12_000003_create_agent_operations.php');
        (new \CreateAgentOperations())->up();
        Schema::create('v2_machine', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        DB::table('v2_machine')->insert(['id' => 9, 'name' => 'entry-machine']);
        Schema::table('v2_server_metadata', fn (Blueprint $t) => $t->string('display_group')->nullable());
    }

    private function request(array $data = [], int $admin = 1): Request
    {
        return Request::create('/', 'POST', $data + ['user' => ['id' => $admin]]);
    }

    private function agent(array $tools = ['preview_node_rename', 'preview_node_sort', 'preview_node_ip', 'preview_entry_ip']): PanelAgentService
    {
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->andReturn(['enabled' => true, 'tools' => $tools]);
        return $agent;
    }

    private function renameRows(): array
    {
        return (new NodePresentationService())->preview('rename', ['changes' => [['key' => 'v2node:1', 'value' => 'New A'], ['key' => 'v2node:2', 'value' => 'New B']]]);
    }

    private function expectHttp(int $status, callable $call): void
    {
        try { $call(); $this->fail('Expected HTTP ' . $status); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    public function test_snapshot_and_undo_touch_only_the_changed_fields(): void
    {
        $service = new AgentOperationService();
        $result = $service->apply((string) Str::uuid(), 1, 'preview_node_rename', $this->renameRows());
        $this->assertEquals(2, $result['updated']);
        $this->assertSame('New A', ServerV2node::find(1)->name);
        $this->assertStringNotContainsString('New A', DB::table('v2_agent_operation')->value('snapshot'));
        $this->assertCount(2, json_decode(Crypt::decryptString(DB::table('v2_agent_operation')->value('snapshot')), true));
        ServerV2node::whereKey(1)->update(['sort' => 50]);
        $preview = $service->undoPreview($result['operation_id'], 1);
        $this->assertSame('New A', $preview['rows'][0]['before']);
        $this->assertSame('A', $preview['rows'][0]['after']);
        $service->undo($result['operation_id'], 1);
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->assertSame('B', ServerV2node::find(2)->name);
        $this->assertSame(50, (int) ServerV2node::find(1)->sort);
        $this->assertSame(7, (int) ServerV2node::find(1)->parent_id);
        $this->assertSame(3, (int) ServerV2node::find(1)->machine_id);
        $this->assertSame(443, (int) ServerV2node::find(1)->port);
        $this->assertSame('undone', DB::table('v2_agent_operation')->value('status'));
    }

    public function test_visibility_requires_approval_preserves_deployment_and_supports_undo(): void
    {
        Schema::table('v2_server_v2node', fn (Blueprint $t) => $t->boolean('show')->default(true));
        $agent = $this->agent(['preview_node_visibility']);
        $args = ['changes' => [['key' => 'v2node:1', 'value' => false]]];
        $preview = $agent->executeTool('preview_node_visibility', $args, ['preview_node_visibility'], $this->request())['confirmation'];
        $this->assertSame('show', $preview['rows'][0]['field']);
        $this->assertSame(0, $preview['rows'][0]['after']);
        $this->assertEquals(1, ServerV2node::find(1)->show);
        $token = $preview['approvals'][0]['token'];
        $result = $agent->confirmBatch([$token], $this->request());
        $this->assertEquals(0, ServerV2node::find(1)->show);
        $this->assertEquals(1, ServerV2node::find(2)->show);
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->assertEquals(7, ServerV2node::find(1)->parent_id);
        $this->assertEquals(443, ServerV2node::find(1)->port);
        $this->assertSame('192.0.2.1', ServerV2node::find(1)->host);
        $this->assertSame([], (new NodePresentationService())->preview('visibility', $args));
        (new AgentOperationService())->undo($result['operations'][0]['operation_id'], 1);
        $this->assertEquals(1, ServerV2node::find(1)->show);
        $this->expectHttp(403, fn () => $agent->executeTool('preview_node_visibility', $args, [], $this->request()));
    }

    public function test_failed_snapshot_does_not_apply_any_changes(): void
    {
        Crypt::shouldReceive('encryptString')->once()->andThrow(new \RuntimeException('simulated snapshot failure'));
        try { (new AgentOperationService())->apply((string) Str::uuid(), 1, 'preview_node_rename', $this->renameRows()); $this->fail('Should fail'); }
        catch (\RuntimeException $e) { $this->assertSame('simulated snapshot failure', $e->getMessage()); }
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->assertEquals(0, DB::table('v2_agent_operation')->count());
    }

    public function test_stale_apply_and_conflicting_undo_are_all_or_nothing(): void
    {
        $service = new AgentOperationService();
        $rows = $this->renameRows();
        ServerV2node::whereKey(2)->update(['name' => 'Other B']);
        $this->expectHttp(409, fn () => $service->apply((string) Str::uuid(), 1, 'preview_node_rename', $rows));
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->assertEquals(0, DB::table('v2_agent_operation')->count());
        $result = $service->apply((string) Str::uuid(), 1, 'preview_node_rename', $this->renameRows());
        ServerV2node::whereKey(2)->update(['name' => 'Later B']);
        $this->expectHttp(409, fn () => $service->undoPreview($result['operation_id'], 1));
        $this->expectHttp(409, fn () => $service->undo($result['operation_id'], 1));
        $this->assertSame('New A', ServerV2node::find(1)->name);
        $this->assertSame('Later B', ServerV2node::find(2)->name);
        $this->assertSame('applied', DB::table('v2_agent_operation')->value('status'));
    }

    public function test_entry_machine_preview_uses_agent_authorization_and_restores_null_override(): void
    {
        ServerMetadata::create(['server_type' => 'v2node', 'server_id' => 1, 'entry_machine_id' => 9, 'entry_host' => null, 'display_group' => 'Keep']);
        $agent = $this->agent();
        $action = $agent->executeTool('preview_entry_ip', ['machine_id' => 9, 'host' => '2001:db8::9'], ['preview_entry_ip'], $this->request())['confirmation'];
        $this->assertSame('node_changes', $action['kind']);
        $this->assertNull(Cache::get('entry-preview:' . $action['token']));
        $this->expectHttp(403, fn () => $this->agent([])->confirm($action['token'], $this->request()));
        $denied = (new \App\Http\Controllers\V2\Admin\AgentController())->confirm($this->request(['token' => $action['token']]), $this->agent([]));
        $this->assertSame(409, $denied->getStatusCode());
        $this->assertSame('agent_permission_denied', json_decode($denied->getContent(), true)['code']);
        $this->assertNull(ServerMetadata::first()->entry_host);
        $result = $agent->confirmWithReceipt($action['token'], $this->request());
        $this->assertSame('2001:db8::9', ServerMetadata::first()->entry_host);
        (new AgentOperationService())->undo($result['operation_id'], 1);
        $this->assertNull(ServerMetadata::first()->entry_host);
        $this->assertEquals(9, ServerMetadata::first()->entry_machine_id);
        $this->assertSame('Keep', ServerMetadata::first()->display_group);
        $this->assertSame('192.0.2.1', ServerV2node::find(1)->host);
    }

    public function test_rebound_entry_cannot_apply_or_undo_old_preview(): void
    {
        $entry = ServerMetadata::create(['server_type' => 'v2node', 'server_id' => 1, 'entry_machine_id' => 9, 'entry_host' => null]);
        $rows = (new NodePresentationService())->previewEntryMachine(['machine_id' => 9, 'host' => '192.0.2.9']);
        $entry->update(['entry_machine_id' => 10]);
        $service = new AgentOperationService();
        $this->expectHttp(409, fn () => $service->apply((string) Str::uuid(), 1, 'preview_entry_ip', $rows));
        $entry->update(['entry_machine_id' => 9]);
        $result = $service->apply((string) Str::uuid(), 1, 'preview_entry_ip', $rows);
        $entry->update(['entry_machine_id' => 10]);
        $this->expectHttp(409, fn () => $service->undo($result['operation_id'], 1));
        $this->assertSame('192.0.2.9', $entry->fresh()->entry_host);
    }

    public function test_confirmation_is_idempotent_even_after_cache_loss_and_undo(): void
    {
        $agent = $this->agent();
        $action = $agent->executeTool('preview_node_rename', ['changes' => [['key' => 'v2node:1', 'value' => 'New A']]], ['preview_node_rename'], $this->request())['confirmation'];
        $result = $agent->confirmWithReceipt($action['token'], $this->request());
        $this->assertSame($result, $agent->confirmWithReceipt($action['token'], $this->request()));
        $this->assertEquals(1, DB::table('v2_agent_operation')->count());
        (new AgentOperationService())->undo($result['operation_id'], 1);
        $retry = $agent->confirmWithReceipt($action['token'], $this->request());
        $this->assertSame('undone', $retry['status']);
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->expectHttp(409, fn () => $agent->confirm($action['token'], $this->request([], 2)));
    }

    public function test_cancelled_expired_and_other_admin_tokens_cannot_modify(): void
    {
        $agent = $this->agent();
        $action = $agent->executeTool('preview_node_sort', ['keys' => ['v2node:2']], ['preview_node_sort'], $this->request())['confirmation'];
        $this->expectHttp(409, fn () => $agent->confirm($action['token'], $this->request([], 2)));
        (new AgentOperationController())->cancel($this->request(['token' => $action['token']]));
        $this->expectHttp(409, fn () => $agent->confirm($action['token'], $this->request()));
        $this->expectHttp(409, fn () => $agent->confirm((string) Str::uuid(), $this->request()));
        $this->assertEquals(1, ServerV2node::find(1)->sort);
        $this->assertEquals(0, DB::table('v2_agent_operation')->count());
    }

    public function test_history_is_paginated_and_admin_scoped(): void
    {
        $service = new AgentOperationService();
        $result = $service->apply((string) Str::uuid(), 1, 'preview_node_rename', $this->renameRows());
        $history = $service->history(1);
        $this->assertEquals(1, $history['total']);
        $this->assertArrayNotHasKey('snapshot', (array) $history['data'][0]);
        $this->assertArrayNotHasKey('token_hash', (array) $history['data'][0]);
        $this->assertEquals(0, $service->history(2)['total']);
        $this->expectHttp(404, fn () => $service->detail($result['operation_id'], 2));
        $this->expectHttp(404, fn () => $service->undo($result['operation_id'], 2));
    }

    public function test_rejection_receipt_survives_preview_expiry_and_is_admin_scoped(): void
    {
        $action = $this->agent()->executeTool('preview_node_rename', ['changes' => [['key' => 'v2node:1', 'value' => 'New A']]], ['preview_node_rename'], $this->request())['confirmation'];
        (new AgentOperationController())->cancel($this->request(['token' => $action['token']]));
        $action['expires_at'] = time() - 1;
        $runs = new \App\Services\AgentRunService();
        $method = new \ReflectionMethod($runs, 'receipts'); $method->setAccessible(true);
        $turns = [['actions' => [$action]]];
        $result = $method->invoke($runs, 1, $turns);
        $this->assertTrue($result[0]['actions'][0]['approvals'][0]['cancelled']);
        $result = $method->invoke($runs, 2, $turns);
        $this->assertArrayNotHasKey('cancelled', $result[0]['actions'][0]['approvals'][0]);
        $this->assertSame('A', ServerV2node::find(1)->name);
    }

    public function test_undo_requires_fresh_preview_and_revalidates_at_confirmation(): void
    {
        $service = new AgentOperationService();
        $result = $service->apply((string) Str::uuid(), 1, 'preview_node_rename', $this->renameRows());
        $controller = new AgentOperationController();
        $response = $controller->undoPreview($this->request(['id' => $result['operation_id']]), $service);
        $token = json_decode($response->getContent(), true)['data']['token'];
        $this->expectHttp(409, fn () => $controller->undo($this->request(['token' => $token], 2), $service));
        ServerV2node::whereKey(1)->update(['name' => 'Manual A']);
        $this->expectHttp(409, fn () => $controller->undo($this->request(['token' => $token]), $service));
        $this->assertSame('New B', ServerV2node::find(2)->name);
    }

    public function test_all_operation_endpoints_require_admin_authentication(): void
    {
        foreach (['agent/operations', 'agent/operation', 'agent/undo-preview', 'agent/undo', 'agent/cancel', 'agent/confirm-batch'] as $suffix) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), '/' . $suffix));
            $this->assertNotNull($route);
            $this->assertContains('admin', $route->gatherMiddleware());
            if (in_array('GET', $route->methods(), true)) $this->getJson('/' . $route->uri())->assertStatus(403);
            else $this->postJson('/' . $route->uri(), ['user' => ['id' => 1]])->assertStatus(403);
        }
    }

    private function renameApproval(): array
    {
        return $this->agent()->executeTool('preview_node_rename', ['changes' => [['key' => 'v2node:1', 'value' => 'New A'], ['key' => 'v2node:2', 'value' => 'New B']]], ['preview_node_rename'], $this->request())['confirmation'];
    }

    public function test_single_approval_leaves_other_nodes_unchanged_and_rejected_item_cannot_execute(): void
    {
        $action = $this->renameApproval();
        $this->assertCount(2, $action['approvals']);
        $first = $action['approvals'][0]['token'];
        $second = $action['approvals'][1]['token'];
        $agent = $this->agent();
        $result = $agent->confirmBatch([$first], $this->request());
        $this->assertEquals(1, $result['updated']);
        $this->assertSame('New A', ServerV2node::find(1)->name);
        $this->assertSame('B', ServerV2node::find(2)->name);
        (new AgentOperationController())->cancel($this->request(['token' => $second]));
        $this->expectHttp(409, fn () => $agent->confirmBatch([$first, $second], $this->request()));
        $this->assertSame('B', ServerV2node::find(2)->name);
        $this->assertEquals(1, DB::table('v2_agent_operation')->count());
    }

    public function test_approve_all_is_atomic_and_retry_does_not_repeat_writes(): void
    {
        $tokens = array_column($this->renameApproval()['approvals'], 'token');
        $agent = $this->agent();
        ServerV2node::whereKey(2)->update(['name' => 'Manual B']);
        $this->expectHttp(409, fn () => $agent->confirmBatch($tokens, $this->request()));
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->assertEquals(0, DB::table('v2_agent_operation')->count());
        $this->assertNotNull(Cache::get('agent-action:' . $tokens[0]));
        ServerV2node::whereKey(2)->update(['name' => 'B']);
        $result = $agent->confirmBatch($tokens, $this->request());
        $this->assertEquals(2, $result['updated']);
        $this->assertCount(2, $result['operations']);
        $this->assertSame('New A', ServerV2node::find(1)->name);
        $this->assertSame('New B', ServerV2node::find(2)->name);
        $retry = $agent->confirmBatch($tokens, $this->request());
        $this->assertEquals(0, $retry['updated']);
        $this->assertEquals(2, DB::table('v2_agent_operation')->count());
    }

    public function test_sort_is_one_approval_unit_and_batch_cannot_inject_rows(): void
    {
        $agent = $this->agent();
        $action = $agent->executeTool('preview_node_sort', ['keys' => ['v2node:2']], ['preview_node_sort'], $this->request())['confirmation'];
        $this->assertCount(1, $action['approvals']);
        $this->assertNull($action['approvals'][0]['key']);
        $result = $agent->confirmBatch([$action['approvals'][0]['token']], $this->request(['rows' => [['id' => 1, 'after' => 'INJECTED']]]));
        $this->assertEquals(2, $result['updated']);
        $this->assertSame('A', ServerV2node::find(1)->name);
        $this->assertEquals(1, ServerV2node::find(2)->sort);
    }
}
