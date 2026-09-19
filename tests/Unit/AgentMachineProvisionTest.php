<?php
namespace Tests\Unit;

use App\Services\AgentMachineProvision;
use App\Models\ServerV2node;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentMachineProvisionTest extends NodePresentationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('a',32))]);
        Schema::create('v2_machine',function(Blueprint $t){$t->increments('id');$t->string('name');$t->string('host');$t->text('relay_rules')->nullable();$t->integer('updated_at')->nullable();});
        Schema::create('v2_server_group',function(Blueprint $t){$t->increments('id');$t->string('name');});
        DB::table('v2_machine')->insert(['id'=>10,'name'=>'entry','host'=>'192.0.2.10']);
        DB::table('v2_server_group')->insert(['id'=>1,'name'=>'test']);
        Schema::table('v2_server_v2node',function(Blueprint $t){$t->integer('relay_machine_id')->nullable();$t->integer('server_port')->nullable();$t->string('protocol')->default('vless');$t->boolean('show')->default(true);});
        require_once database_path('migrations/2026_09_12_000003_create_agent_operations.php');(new \CreateAgentOperations())->up();
    }
    private function args(): array
    {
        return ['machine_id'=>10,'tcp_only'=>true,'entries'=>[['kind'=>'local','name'=>'test local','host'=>'192.0.2.10','port'=>12345,'listen_port'=>12345,'protocol'=>'anytls','group_id'=>[1],'rate'=>1]]];
    }
    public function test_preview_then_approved_application_calls_existing_controller_and_keeps_hidden(): void
    {
        $service=new AgentMachineProvision();$preview=$service->plan($this->args(),1)['confirmation'];
        $this->assertSame(2,ServerV2node::count());
        $task=\Illuminate\Support\Facades\Cache::get('agent-action:'.$preview['token']);
        $controller=\Mockery::mock(\App\Http\Controllers\V1\Admin\MachineController::class);
        $controller->shouldReceive('createV2node')->once()->andReturnUsing(function($request){
            $this->assertSame(0,$request->input('show'));
            $this->assertSame(10,$request->input('machine_id'));
            $n=ServerV2node::create(['name'=>'test local','sort'=>3,'host'=>'192.0.2.10','port'=>12345,'machine_id'=>10,'parent_id'=>0,'show'=>0]);
            return response()->json(['data'=>['id'=>$n->id]]);
        });
        $this->app->instance(\App\Http\Controllers\V1\Admin\MachineController::class,$controller);
        $out=$service->apply($preview['token'],1,$task['rows']);
        $this->assertSame('pending_verification',$out['deployment_status']);
        $this->assertSame(3,ServerV2node::count());
        $this->assertSame(1,DB::table('v2_agent_operation')->count());
    }
    public function test_tcp_only_rejects_udp_target(): void
    {
        ServerV2node::whereKey(1)->update(['parent_id'=>0,'protocol'=>'hysteria2']);
        $args=$this->args();$args['entries'][0]['kind']='forward';$args['entries'][0]['parent_id']=1;
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new AgentMachineProvision())->plan($args,1);
    }
    public function test_forward_creates_bound_child_with_parent_target_port(): void
    {
        ServerV2node::whereKey(1)->update(['parent_id'=>0,'server_port'=>443]);
        $args=$this->args();$args['entries'][0]['kind']='forward';$args['entries'][0]['parent_id']=1;
        $s=new AgentMachineProvision();$p=$s->plan($args,1)['confirmation'];
        $task=\Illuminate\Support\Facades\Cache::get('agent-action:'.$p['token']);
        $controller=\Mockery::mock(\App\Http\Controllers\V1\Admin\Server\V2nodeController::class);
        $controller->shouldReceive('save')->once()->andReturnUsing(function($r){
            $this->assertSame(1,$r->input('parent_id'));$this->assertSame(10,$r->input('relay_machine_id'));
            $this->assertSame(443,$r->input('server_port'));$this->assertSame(12345,$r->input('port'));$this->assertSame(0,$r->input('show'));
            ServerV2node::create(['name'=>$r->input('name'),'host'=>$r->input('host'),'port'=>12345,'server_port'=>443,'parent_id'=>1,'machine_id'=>3,'relay_machine_id'=>10,'sort'=>3,'show'=>0]);
            return response()->json(['data'=>true]);
        });
        $this->app->instance(\App\Http\Controllers\V1\Admin\Server\V2nodeController::class,$controller);
        $out=$s->apply($p['token'],1,$task['rows']);$this->assertSame(1,$out['updated']);
        $this->assertSame('A',ServerV2node::find(1)->name);
    }
    public function test_existing_manual_forward_port_blocks_plan(): void
    {
        DB::table('v2_machine')->where('id',10)->update(['relay_rules'=>json_encode([['listen_port'=>12345]])]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new AgentMachineProvision())->plan($this->args(),1);
    }
}
