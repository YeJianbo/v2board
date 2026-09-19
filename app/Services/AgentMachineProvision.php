<?php
namespace App\Services;

use App\Models\Machine;
use App\Models\ServerV2node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AgentMachineProvision
{
    public function plan(array $args, int $admin): array
    {
        $d=Validator::make($args,[
            'machine_id'=>'required|integer|exists:v2_machine,id','tcp_only'=>'required|boolean',
            'entries'=>'required|array|min:1|max:20','entries.*.kind'=>'required|in:local,forward',
            'entries.*.name'=>'required|string|max:150','entries.*.host'=>'required|ip',
            'entries.*.port'=>'required|integer|min:1|max:65535','entries.*.listen_port'=>'required|integer|min:1|max:65535',
            'entries.*.parent_id'=>'nullable|integer|exists:v2_server_v2node,id',
            'entries.*.protocol'=>'nullable|in:anytls,trojan,vless,vmess,shadowsocks,tuic,hysteria2',
            'entries.*.group_id'=>'required|array|min:1','entries.*.group_id.*'=>'integer|exists:v2_server_group,id',
            'entries.*.rate'=>'required|numeric|min:0|max:100',
        ])->validate();
        $machine=Machine::findOrFail($d['machine_id']); $rows=[]; $ports=[];
        foreach($d['entries'] as $i=>$e){
            $parent=null;
            if($e['kind']==='forward'){
                $parent=ServerV2node::findOrFail($e['parent_id']??0);
                abort_if($parent->parent_id || !$parent->machine_id || (int)$parent->machine_id===(int)$machine->id,422,'请选择其他机器上的根节点作为落地');
                $protocol=$parent->protocol;
            }else{
                $protocol=$e['protocol']??'';
                // Local TLS defaults are already handled by the existing machine controller.
                abort_unless($protocol==='anytls',422,'本机自动创建当前支持AnyTLS；其他协议请在节点编辑器中明确TLS等配置');
            }
            abort_if($d['tcp_only'] && !in_array($protocol,['anytls','vless','vmess','trojan'],true),422,'TCP-only入口仅支持明确使用TCP的协议：'.$protocol);
            abort_if(isset($ports[$e['listen_port']]),422,'计划中的监听端口重复');$ports[$e['listen_port']]=true;
            $this->assertPortFree($machine,$e['listen_port']);
            if($parent) abort_unless(filter_var($parent->host,FILTER_VALIDATE_IP)||filter_var('https://'.$parent->host,FILTER_VALIDATE_URL),422,'落地地址无效');
            if($parent) abort_unless($e['listen_port']===$e['port'],422,'自动转发暂不支持外部端口与内部监听端口不一致');
            $rows[]=['key'=>'new:'.$i,'name'=>$e['name'],'field'=>'create','before'=>'未创建','after'=>($parent?'创建转发子节点与自动GOST规则':'创建本机AnyTLS节点').'；隐藏待验证','port'=>$e['port'],
                'machine_id'=>(int)$machine->id,'machine_updated_at'=>(string)$machine->updated_at,'entry'=>$e,'parent_snapshot'=>$parent?hash('sha256',$parent->toJson()):null];
        }
        $token=(string)Str::uuid();
        Cache::put('agent-action:'.$token,['admin_id'=>$admin,'tool'=>'preview_machine_provision','rows'=>$rows],300);
        return ['confirmation'=>['kind'=>'node_changes','token'=>$token,'approvals'=>[['token'=>$token,'key'=>null]],'operation'=>'provision','expires_at'=>time()+300,
            'rows'=>array_map(fn($r)=>array_intersect_key($r,array_flip(['key','name','field','before','after','port'])),$rows)]];
    }

    private function assertPortFree(Machine $machine,int $port): void
    {
        foreach(ServerV2node::where(function($q)use($machine){$q->where('machine_id',$machine->id)->orWhere('relay_machine_id',$machine->id);})->get() as $n){
            if($n->parent_id && (int)$n->relay_machine_id!==(int)$machine->id)continue;
            $used=(int)$n->relay_machine_id===(int)$machine->id?(int)$n->port:(int)($n->server_port?:$n->port);
            abort_if($used===$port,422,'监听端口已被面板节点占用：'.$port);
        }
        foreach($machine->relay_rules??[] as $r)abort_if((int)($r['listen_port']??$r['listenPort']??0)===$port,422,'监听端口已被手动转发占用：'.$port);
    }

    public function apply(string $token,int $admin,array $rows): array
    {
        return DB::transaction(function()use($token,$admin,$rows){
            $machine=Machine::whereKey($rows[0]['machine_id'])->lockForUpdate()->firstOrFail();
            foreach($rows as $row){
                abort_unless((int)$row['machine_id']===(int)$machine->id && (string)$machine->updated_at===$row['machine_updated_at'],409,'机器已变更，请重新生成预览');
                $this->assertPortFree($machine,$row['entry']['listen_port']);
                if($row['parent_snapshot']){ $parent=ServerV2node::whereKey($row['entry']['parent_id'])->lockForUpdate()->firstOrFail(); abort_unless(hash('sha256',$parent->toJson())===$row['parent_snapshot'],409,'落地节点已变更，请重新预览'); }
            }
            $created=[];
            foreach($rows as $row){
                $e=$row['entry'];
                if($e['kind']==='local'){
                    $response=app(\App\Http\Controllers\V1\Admin\MachineController::class)->createV2node(Request::create('/','POST',[
                        'machine_id'=>$machine->id,'name'=>$e['name'],'host'=>$e['host'],'port'=>$e['port'],'server_port'=>$e['listen_port'],
                        'protocol'=>'anytls','group_id'=>$e['group_id'],'rate'=>$e['rate'],'show'=>0,
                        'tls_settings'=>NodeTlsBootstrap::anytls([])]));
                    $id=json_decode($response->getContent(),true)['data']['id'];
                }else{
                    $parent=ServerV2node::findOrFail($e['parent_id']);$payload=$parent->toArray();
                    unset($payload['id'],$payload['created_at'],$payload['updated_at']);
                    if (isset($payload['padding_scheme']) && is_array($payload['padding_scheme'])) $payload['padding_scheme'] = json_encode($payload['padding_scheme'], JSON_THROW_ON_ERROR);
                    $payload=array_merge($payload,['parent_id'=>$parent->id,'relay_machine_id'=>$machine->id,'name'=>$e['name'],'host'=>$e['host'],'port'=>$e['port'],
                        'server_port'=>$parent->server_port?:$parent->port,'group_id'=>$e['group_id'],'rate'=>$e['rate'],'show'=>0]);
                    app(\App\Http\Controllers\V1\Admin\Server\V2nodeController::class)->save(Request::create('/','POST',$payload));
                    $id=ServerV2node::where('relay_machine_id',$machine->id)->where('parent_id',$parent->id)->where('port',$e['port'])->latest('id')->value('id');
                }
                abort_unless($id,500,'创建结果缺少节点ID');
                $created[]=['key'=>'v2node:'.$id,'name'=>$e['name'],'field'=>'create','before'=>'未创建','after'=>'已创建并提交同步；隐藏待验证','port'=>$e['port']];
            }
            $id=DB::table('v2_agent_operation')->insertGetId(['admin_id'=>$admin,'token_hash'=>hash('sha256',$token),'tool'=>'preview_machine_provision','node_count'=>count($created),
                'snapshot'=>Crypt::encryptString(json_encode($created,JSON_THROW_ON_ERROR)),'created_at'=>time(),'status'=>'applied']);
            return ['operation_id'=>$id,'updated'=>count($created),'status'=>'applied','deployment_status'=>'pending_verification','nodes'=>$created];
        });
    }
}
