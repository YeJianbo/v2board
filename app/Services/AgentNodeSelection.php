<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class AgentNodeSelection
{
    public function select(array $nodes, array $filters, int $admin): array
    {
        Validator::make(['filters'=>$filters], ['filters'=>'array:name,cidr,machine_id,parent_key,protocol,show,online,family,keys'])->validate();
        $f = Validator::make($filters, [
            'name' => 'sometimes|string|max:200', 'cidr' => 'sometimes|string|max:100',
            'machine_id' => 'sometimes|integer|min:1', 'parent_key' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]*:[1-9][0-9]*$/D'],
            'protocol' => 'sometimes|string|max:30', 'show' => 'sometimes|boolean', 'online' => 'sometimes|boolean',
            'family' => 'sometimes|in:4,6', 'keys' => 'sometimes|array', 'keys.*' => 'string',
        ])->validate();
        if (isset($f['cidr'])) {
            $parts = explode('/', $f['cidr']); $bytes = @inet_pton($parts[0]);
            abort_unless($bytes !== false && count($parts) <= 2 && (!isset($parts[1]) || (ctype_digit($parts[1]) && (int)$parts[1] <= strlen($bytes)*8)), 422, '请输入完整 IP 或 CIDR，如 206.0.0.0/8');
        }
        $matched = array_values(array_filter($nodes, function ($n) use ($f) {
            $host = !empty($n['entry_host']) ? $n['entry_host'] : ($n['host'] ?? '');
            if (isset($f['name']) && mb_stripos($n['name'] ?? '', $f['name']) === false) return false;
            if (isset($f['keys']) && !in_array($n['key'], $f['keys'], true)) return false;
            if (isset($f['protocol']) && ($n['protocol'] ?? '') !== $f['protocol']) return false;
            foreach (['show' => 'show', 'online' => 'is_online'] as $filter => $field) if (isset($f[$filter]) && (!array_key_exists($field, $n) || (bool)$n[$field] !== (bool)$f[$filter])) return false;
            if (isset($f['machine_id']) && (int)($n['machine_id'] ?? 0) !== (int)$f['machine_id']) return false;
            if (isset($f['parent_key']) && (($n['type'] ?? '') . ':' . ($n['parent_id'] ?? '')) !== $f['parent_key']) return false;
            if (isset($f['family']) && !filter_var($host, FILTER_VALIDATE_IP, $f['family'] == 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) return false;
            if (isset($f['cidr']) && (!filter_var($host, FILTER_VALIDATE_IP) || !IpUtils::checkIp($host, $f['cidr']))) return false;
            return true;
        }));
        $id = (string)Str::uuid();
        Cache::put('agent-selection:' . $id, ['admin' => $admin, 'nodes' => $matched], 600);
        return ['selection_id' => $id, 'count' => count($matched), 'expires_in' => 600, 'nodes' => $matched,
            'next_step' => !$matched ? '没有匹配项。核对名称或询问用户，不要原样重复查询。' : '已有完整目标集合。批量修改使用此selection_id；入口转发只选根节点并检查协议。不要重新查询相同条件。'];
    }

    public function resolve(string $id, int $admin, array $current): array
    {
        $saved = Cache::get('agent-selection:' . $id);
        abort_unless($saved && $saved['admin'] === $admin, 409, '目标集合已过期或不属于当前管理员，请重新筛选');
        $index = array_column($current, null, 'key');
        foreach ($saved['nodes'] as $n) {
            $now = $index[$n['key']] ?? null;
            abort_unless($now, 409, '目标节点已删除，请重新筛选');
            foreach (['name','host','entry_host','port','type','parent_id','machine_id','protocol','show','sort'] as $f) abort_unless(($n[$f] ?? null) === ($now[$f] ?? null), 409, '目标节点已变更，请重新筛选');
        }
        return $saved['nodes'];
    }

    public function changes(array $nodes, array $args): array
    {
        $d = Validator::make($args, ['operation'=>'required|in:rename,visibility,ip,sort', 'mode'=>'sometimes|in:prefix,suffix,replace,number',
            'text'=>'sometimes|string|max:150', 'find'=>'sometimes|string|min:1|max:150', 'replacement'=>'sometimes|nullable|string|max:150',
            'start'=>'sometimes|integer|min:0|max:999999', 'width'=>'sometimes|integer|min:1|max:6',
            'show'=>'sometimes|boolean', 'ip'=>'sometimes|ip'])->validate();
        abort_unless($nodes, 422, '目标集合为空');
        if ($d['operation'] === 'sort') return ['keys' => array_column($nodes, 'key')];
        if ($d['operation'] === 'visibility') abort_unless(array_key_exists('show',$d),422,'需要指定show');
        if ($d['operation'] === 'ip') abort_unless(isset($d['ip']),422,'需要指定ip');
        if ($d['operation'] === 'rename') {
            abort_unless(isset($d['mode']),422,'需要指定命名模式');
            if ($d['mode'] === 'replace') abort_unless(isset($d['find']),422,'需要指定待替换文字');
            else abort_unless(isset($d['text']),422,'需要指定名称文字');
        }
        $changes=[];
        foreach ($nodes as $i=>$n) {
            if ($d['operation']==='visibility') $value=(bool)$d['show'];
            elseif ($d['operation']==='ip') $value=$d['ip'];
            else switch($d['mode']) {
                case 'prefix': $value=$d['text'].$n['name']; break;
                case 'suffix': $value=$n['name'].$d['text']; break;
                case 'replace': $value=str_replace($d['find'],$d['replacement']??'',$n['name']); break;
                default: $value=$d['text'].str_pad((string)(($d['start']??1)+$i),$d['width']??2,'0',STR_PAD_LEFT);
            }
            $changes[]=['key'=>$n['key'],'value'=>$value];
        }
        return ['changes'=>$changes];
    }
}
