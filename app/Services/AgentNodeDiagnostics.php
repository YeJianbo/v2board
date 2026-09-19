<?php
namespace App\Services;

use App\Models\Machine;
use App\Models\ServerV2node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgentNodeDiagnostics
{
    private function machines(Request $request): array
    {
        $response=app(\App\Http\Controllers\V1\Admin\MachineController::class)->status($request);
        return array_column(json_decode($response->getContent(),true)['data'],null,'id');
    }
    public function health(array $nodes, Request $request): array
    {
        $machines=$this->machines($request);$result=[];
        foreach($nodes as $n){
            $m=$machines[$n['machine_id']??0]??null;$s=$m['status_data']??[];
            $result[]=['key'=>$n['key'],'name'=>$n['name'],'subscription_visible'=>$n['show']??null,
                'node_report_online'=>$n['is_online']??null,'last_active_at'=>$n['last_active_at']??null,
                'machine_id'=>$n['machine_id'],'machine_online'=>$m['is_online']??null,
                'reported'=>array_intersect_key($s,array_flip(['config_apply_status','v2node_status','gost_status'])),
                'port_check'=>'not_tested','protocol_handshake'=>'not_tested'];
        }
        return ['count'=>count($result),'nodes'=>$result];
    }
    public function onboarding(array $args, Request $request): array
    {
        $d=Validator::make($args,['machine_id'=>'required|integer|exists:v2_machine,id','protocol'=>'sometimes|in:shadowsocks,vmess,vless,trojan,tuic,hysteria2,anytls','port'=>'sometimes|integer|min:1|max:65535'])->validate();
        $machine=Machine::findOrFail($d['machine_id']);$conflicts=[];$usedPorts=[];
        $udp=in_array($d['protocol']??'', ['tuic','hysteria2'],true);
        foreach(ServerV2node::where('machine_id',$machine->id)->whereNull('parent_id')->get() as $n){
            $port=(int)($n->server_port?:$n->port);
            $usedPorts[]=$port;
            if(!isset($d['port'],$d['protocol']) || $port!==$d['port'])continue;
            $otherUdp=in_array($n->protocol,['tuic','hysteria2'],true);
            if($udp===$otherUdp||$n->protocol==='shadowsocks'||$d['protocol']==='shadowsocks')$conflicts[]=['key'=>'v2node:'.$n->id,'name'=>$n->name,'port'=>$port,'protocol'=>$n->protocol];
        }
        $m=$this->machines($request)[$machine->id]??[];
        $relayNodes=ServerV2node::where('relay_machine_id',$machine->id)->get(['id','name','parent_id','port','host','protocol','show']);
        foreach($relayNodes as $n)$usedPorts[]=(int)$n->port;
        foreach($machine->relay_rules??[] as $rule)$usedPorts[]=(int)($rule['listen_port']??0);
        return ['machine_id'=>$machine->id,'machine_name'=>$machine->name,'machine_online'=>$m['is_online']??null,
            'entry_host'=>$machine->host,'reported_ip'=>$m['status_data']['primary_ip']??null,'entry_host_note'=>'配置入口为空时，上报IP不一定是入站地址；NAT机器需确认公网端口映射',
            'group_options'=>\App\Models\ServerGroup::orderBy('id')->get(['id','name'])->toArray(),
            'existing_forward_nodes'=>$relayNodes->toArray(),'existing_relay_rules'=>$machine->relay_rules??[],
            'occupied_ports'=>array_values(array_unique(array_filter($usedPorts))),
            'protocol'=>$d['protocol']??null,'port'=>$d['port']??null,'panel_port_conflicts'=>$conflicts,'created'=>false,'deployed'=>false,
            'checks_remaining'=>['实际端口及GOST冲突','入口与监听地址','TLS或Reality参数及证书','用户组与倍率','创建批准','Ravel应用回执','协议握手'],
            'next_page'=>'nodes/machines'];
    }
}
