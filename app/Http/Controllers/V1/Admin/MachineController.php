<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\ServerGroup;
use App\Models\ServerV2node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    public function fetch(Request $request)
    {
        $onlineWindowSeconds = 180;

        return response([
            'data' => Machine::orderBy('id', 'DESC')->get()->map(function (Machine $machine) use ($onlineWindowSeconds) {
                $status = null;
                if (!empty($machine->status)) {
                    $decoded = json_decode($machine->status, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $status = $decoded;
                    }
                }

                $lastSeenAt = (int) ($machine->updated_at ?? 0);
                $isOnline = !empty($status) && $lastSeenAt > 0 && (time() - $lastSeenAt) < $onlineWindowSeconds;

                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'host' => $machine->host,
                    'api_token' => $machine->api_token,
                    'status' => $machine->status,
                    'status_data' => $status,
                    'is_online' => $isOnline ? 1 : 0,
                    'last_seen_at' => $lastSeenAt,
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                ];
            })->values()
        ]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required',
            'host' => 'nullable'
        ]);

        if ($request->input('id')) {
            $machine = Machine::findOrFail($request->input('id'));
            $machine->update($params);
        } else {
            $params['api_token'] = Str::random(32);
            Machine::create($params);
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $machine = Machine::findOrFail($request->input('id'));
        $machine->delete();
        return response([
            'data' => true
        ]);
    }

    public function createV2node(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_machine,id',
            'name' => 'required|string',
            'host' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
            'server_port' => 'nullable|integer|min:1|max:65535',
            'protocol' => 'nullable|in:shadowsocks,vmess,vless,trojan,tuic,hysteria2,anytls',
            'group_id' => 'nullable|array',
            'route_id' => 'nullable|array',
            'rate' => 'nullable|numeric',
            'show' => 'nullable|in:0,1',
        ]);

        $machine = Machine::findOrFail($params['machine_id']);
        $groupIds = $params['group_id'] ?? [];
        if (empty($groupIds)) {
            $firstGroupId = ServerGroup::orderBy('id', 'ASC')->value('id');
            if ($firstGroupId) {
                $groupIds = [(int) $firstGroupId];
            }
        }

        if (empty($groupIds)) {
            abort(422, '请先创建至少一个权限组');
        }

        $server = ServerV2node::create([
            'group_id' => array_values(array_map('intval', $groupIds)),
            'route_id' => array_values(array_map('intval', $params['route_id'] ?? [])),
            'name' => $params['name'],
            'parent_id' => null,
            'machine_id' => $machine->id,
            'host' => $params['host'] ?? $machine->host ?: '127.0.0.1',
            'listen_ip' => '0.0.0.0',
            'port' => $params['port'] ?? 443,
            'server_port' => $params['server_port'] ?? ($params['port'] ?? 443),
            'tags' => [],
            'rate' => $params['rate'] ?? 1,
            'show' => $params['show'] ?? 0,
            'sort' => null,
            'protocol' => $params['protocol'] ?? 'vmess',
            'tls' => 0,
            'tls_settings' => [],
            'flow' => null,
            'network' => 'tcp',
            'network_settings' => [],
            'encryption' => null,
            'encryption_settings' => [],
            'disable_sni' => 0,
            'udp_relay_mode' => null,
            'zero_rtt_handshake' => 0,
            'congestion_control' => null,
            'cipher' => null,
            'up_mbps' => 0,
            'down_mbps' => 0,
            'obfs' => null,
            'obfs_password' => null,
            'padding_scheme' => [],
        ]);

        return response([
            'data' => [
                'id' => $server->id,
                'machine_id' => $machine->id,
            ],
        ]);
    }

    // Generate deploy command for the specific machine
    public function deployCommand(Request $request)
    {
        $machine = Machine::findOrFail($request->input('id'));
        $nodeId = $request->input('node_id');
        $nodeType = $request->input('node_type');
        
        $panelUrl = config('v2board.app_url');
        // Command to be executed on the machine, our custom proprietary agent handles this
        $cmd = "v2agent deploy --token={$machine->api_token} --panel={$panelUrl} --node_id={$nodeId} --node_type={$nodeType}";
        
        \Illuminate\Support\Facades\Cache::put('deploy_command_' . $machine->api_token, $cmd, 300);

        return response([
            'data' => true,
            'message' => '指令已下发至探针队列'
        ]);
    }

    public function restartV2node(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
        ]);

        $machine = Machine::findOrFail($params['id']);
        $restartToken = (string) time() . '-' . Str::random(12);

        Cache::put(
            'v2node_probe_restart:' . $machine->id,
            $restartToken,
            300
        );

        return response([
            'data' => [
                'restart_token' => $restartToken,
            ],
            'message' => 'v2node 重启指令已下发',
        ]);
    }
}
