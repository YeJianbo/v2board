<?php
namespace Tests\Unit;

use App\Services\NodePresentationService;
use App\Services\ServerService;
use App\Models\ServerV2node;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodePresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('v2_server_v2node', function (Blueprint $t) {
            $t->increments('id'); $t->string('name'); $t->integer('sort'); $t->string('host'); $t->integer('port'); $t->integer('parent_id'); $t->integer('machine_id'); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        Schema::create('v2_server_metadata', function (Blueprint $t) {
            $t->increments('id'); $t->string('server_type'); $t->integer('server_id'); $t->integer('entry_machine_id')->nullable(); $t->string('entry_host')->nullable(); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        ServerV2node::insert([
            ['id' => 1, 'name' => 'A', 'sort' => 1, 'host' => '192.0.2.1', 'port' => 443, 'parent_id' => 7, 'machine_id' => 3],
            ['id' => 2, 'name' => 'B', 'sort' => 2, 'host' => '192.0.2.2', 'port' => 8443, 'parent_id' => 8, 'machine_id' => 4],
        ]);
        $service = \Mockery::mock(ServerService::class)->makePartial();
        $service->shouldReceive('getAllServers')->andReturnUsing(fn () => ServerV2node::orderBy('sort')->get()->map(fn ($n) => array_merge($n->toArray(), ['type' => 'v2node']))->all());
        $this->app->instance(ServerService::class, $service);
    }

    public function test_rename_and_sort_preserve_parent_machine_and_ports(): void
    {
        $service = new NodePresentationService();
        $rows = $service->preview('rename', ['changes' => [['key' => 'v2node:1', 'value' => 'New A']]]);
        $this->assertSame('A', ServerV2node::find(1)->name);
        $service->apply($rows);
        $this->assertSame('New A', ServerV2node::find(1)->name);
        $service->apply($service->preview('sort', ['keys' => ['v2node:2']]));
        $this->assertSame(1, (int) ServerV2node::find(2)->sort);
        $this->assertSame(2, (int) ServerV2node::find(1)->sort);
        $this->assertSame(7, (int) ServerV2node::find(1)->parent_id);
        $this->assertSame(3, (int) ServerV2node::find(1)->machine_id);
        $this->assertSame(443, (int) ServerV2node::find(1)->port);
    }

    public function test_ip_changes_metadata_only(): void
    {
        $service = new NodePresentationService();
        $service->apply($service->preview('ip', ['changes' => [['key' => 'v2node:1', 'value' => '2001:db8::1']]]));
        $this->assertSame('192.0.2.1', ServerV2node::find(1)->host);
        $this->assertSame('2001:db8::1', DB::table('v2_server_metadata')->value('entry_host'));
    }
}
