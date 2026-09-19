<?php
namespace Tests\Unit;

use App\Models\ServerMetadata;
use App\Services\SubscriptionEntryService;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('v2_server_metadata', function (Blueprint $t) {
            $t->increments('id'); $t->string('server_type'); $t->integer('server_id');
            $t->integer('entry_machine_id')->nullable(); $t->string('entry_host')->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
    }

    public function test_entry_override_is_subscription_only_and_uses_protocol_plus_id(): void
    {
        ServerMetadata::create(['server_type' => 'v2node', 'server_id' => 7, 'entry_machine_id' => 2, 'entry_host' => '2001:db8::1']);
        $nodes = [['type' => 'v2node', 'id' => 7, 'host' => '192.0.2.1', 'port' => 443, 'server_port' => 8443], ['type' => 'vless', 'id' => 7, 'host' => '192.0.2.2', 'port' => 444]];
        $out = (new SubscriptionEntryService())->apply($nodes);
        $this->assertSame('2001:db8::1', $out[0]['host']);
        $this->assertSame(443, $out[0]['port']);
        $this->assertSame(8443, $out[0]['server_port']);
        $this->assertSame('192.0.2.2', $out[1]['host']);
        $this->assertSame('192.0.2.1', $nodes[0]['host']);
    }

    public function test_preview_commit_changes_only_bound_metadata_and_rejects_stale_override(): void
    {
        $entry = ServerMetadata::create(['server_type' => 'v2node', 'server_id' => 7, 'entry_machine_id' => 2]);
        $service = \Mockery::mock(ServerService::class);
        $service->shouldReceive('getAllServers')->andReturn([['type' => 'v2node', 'id' => 7, 'name' => 'node', 'host' => '192.0.2.1', 'port' => 443]]);
        $this->app->instance(ServerService::class, $service);
        $entries = new SubscriptionEntryService();
        $preview = $entries->preview(2, '192.0.2.3');
        $this->assertCount(1, $preview);
        $this->assertSame(1, $entries->commit(2, '192.0.2.3', $preview));
        $this->assertSame('192.0.2.3', $entry->fresh()->entry_host);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $entries->commit(2, '192.0.2.4', $preview);
    }

    public function test_list_without_new_ip_and_safe_backfill(): void
    {
        $existing=ServerMetadata::create(['server_type'=>'v2node','server_id'=>1,'entry_machine_id'=>9,'entry_host'=>'192.0.2.9']);
        $nodes=[
            ['type'=>'v2node','id'=>1,'name'=>'existing','host'=>'192.0.2.1','port'=>443,'relay_machine_id'=>2],
            ['type'=>'v2node','id'=>2,'name'=>'relay','host'=>'192.0.2.2','port'=>444,'relay_machine_id'=>2],
            ['type'=>'vless','id'=>3,'name'=>'legacy','host'=>'192.0.2.3','port'=>445],
            ['type'=>'vless','id'=>4,'name'=>'ambiguous','host'=>'192.0.2.4','port'=>446],
        ];
        $servers=\Mockery::mock(ServerService::class);$servers->shouldReceive('getAllServers')->andReturn($nodes);$this->app->instance(ServerService::class,$servers);
        $service=new SubscriptionEntryService();
        $result=$service->bindConfigured(['192.0.2.3'=>[3],'192.0.2.4'=>[3,4]],[2,3,4,9]);
        $this->assertCount(2,$result['bound']);
        $this->assertSame(['vless:4'],$result['unresolved']);
        $this->assertEquals(9,$existing->fresh()->entry_machine_id);
        $this->assertSame('192.0.2.9',$existing->fresh()->entry_host);
        $rows=$service->preview(2);
        $this->assertCount(1,$rows);
        $this->assertNull($rows[0]['after']);
        $this->assertSame('192.0.2.2',$rows[0]['before']);
        $this->assertNull(ServerMetadata::where('server_id',2)->first()->entry_host);
        $this->assertSame([],$service->bindConfigured(['192.0.2.3'=>[3],'192.0.2.4'=>[3,4]],[2,3,4,9])['bound']);
    }
}
