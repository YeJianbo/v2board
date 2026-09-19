<?php
namespace Tests\Unit;

use App\Services\AgentNodeSelection;
use Tests\TestCase;

class AgentNodeSelectionTest extends TestCase
{
    private function nodes(): array
    {
        return [
            ['key'=>'v2node:1','type'=>'v2node','name'=>'PH 4837','host'=>'206.237.27.96','entry_host'=>null,'show'=>true,'is_online'=>true,'parent_id'=>9,'machine_id'=>2,'protocol'=>'vless'],
            ['key'=>'v2node:2','type'=>'v2node','name'=>'PH 4837','host'=>'206.237.27.96','entry_host'=>'2001:db8::1','show'=>false,'is_online'=>true,'parent_id'=>9,'machine_id'=>2,'protocol'=>'hysteria2'],
            ['key'=>'vless:3','type'=>'vless','name'=>'TW CN2','host'=>'120.6.1.1','show'=>true,'is_online'=>false,'parent_id'=>9],
        ];
    }
    public function test_filters_use_effective_ip_and_parent_storage_type(): void
    {
        $s=new AgentNodeSelection();
        $result=$s->select($this->nodes(),['cidr'=>'206.0.0.0/8','name'=>'4837','show'=>true],1);
        $this->assertSame(['v2node:1'],array_column($result['nodes'],'key'));
        $this->assertCount(2,$s->select($this->nodes(),['parent_key'=>'v2node:9'],1)['nodes']);
        $this->assertSame(['v2node:2'],array_column($s->select($this->nodes(),['family'=>6],1)['nodes'],'key'));
        $this->assertSame($result['nodes'],$s->resolve($result['selection_id'],1,$this->nodes()));
    }
    public function test_selection_rejects_other_admin_and_changed_nodes(): void
    {
        $s=new AgentNodeSelection();$r=$s->select($this->nodes(),[],1);
        foreach([2,1] as $admin){
            $nodes=$this->nodes();if($admin===1)$nodes[0]['name']='changed';
            try{$s->resolve($r['selection_id'],$admin,$nodes);$this->fail('Expected conflict');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        }
    }
    public function test_deterministic_rename_and_visibility(): void
    {
        $s=new AgentNodeSelection();$nodes=array_slice($this->nodes(),0,2);
        $r=$s->changes($nodes,['operation'=>'rename','mode'=>'number','text'=>'PH ','start'=>3,'width'=>2]);
        $this->assertSame(['PH 03','PH 04'],array_column($r['changes'],'value'));
        $r=$s->changes($nodes,['operation'=>'rename','mode'=>'replace','find'=>'4837','replacement'=>'HK']);
        $this->assertSame(['PH HK','PH HK'],array_column($r['changes'],'value'));
        $this->assertSame([false,false],array_column($s->changes($nodes,['operation'=>'visibility','show'=>false])['changes'],'value'));
        $this->assertSame(['v2node:1','v2node:2'],$s->changes($nodes,['operation'=>'sort'])['keys']);
    }
    public function test_invalid_cidr_never_matches_everything(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new AgentNodeSelection())->select($this->nodes(),['cidr'=>'206'],1);
    }
    public function test_unknown_filter_never_silently_broadens_selection(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new AgentNodeSelection())->select($this->nodes(),['country'=>'PH'],1);
    }
}
