<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerV2node;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ParagonIE_Sodium_Compat as SodiumCompat;
use App\Utils\Helper;
use Illuminate\Support\Str;

class V2nodeController extends Controller
{
    private const RESTART_TOKEN_TTL_SECONDS = 300;
    private const DEFAULT_TLS_SERVER_NAME = 'genshin.hoyoverse.com';

    private function notifyMachineNodesChanged(array $machineIds): void
    {
        $machineIds = array_values(array_unique(array_filter(array_map('intval', $machineIds))));

        foreach ($machineIds as $machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
            Cache::put(
                'v2node_probe_restart:' . $machineId,
                (string) time() . '-' . Str::random(12),
                self::RESTART_TOKEN_TTL_SECONDS
            );
        }
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'group_id' => 'required',
            'route_id' => 'nullable|array',
            'name' => 'required',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer|exists:v2_machine,id',
            'relay_machine_id' => 'nullable|integer|exists:v2_machine,id',
            'host' => 'required',
            'listen_ip' => 'nullable',
            'port' => 'required',
            'server_port' => 'required',
            'protocol' => 'required|in:shadowsocks,vmess,vless,trojan,tuic,hysteria2,anytls',
            'tls' => 'required|in:0,1,2',
            'tls_settings' => 'nullable|array',
            'flow' => 'nullable|in:xtls-rprx-vision',
            'network' => 'required|in:tcp,ws,grpc,http,httpupgrade,xhttp',
            'network_settings' => 'nullable|array',
            'encryption' => 'nullable',
            'encryption_settings' => 'nullable|array',
            'disable_sni' => 'required|in:0,1',
            'udp_relay_mode' => 'nullable',
            'zero_rtt_handshake' => 'required|in:0,1',
            'congestion_control' => 'nullable',
            'cipher' => 'nullable',
            'up_mbps' => 'nullable|numeric',
            'down_mbps' => 'nullable|numeric',
            'obfs' => 'nullable',
            'obfs_password' => 'nullable',
            'padding_scheme' => 'nullable',
            'tags' => 'nullable|array',
            'rate' => 'required',
            'show' => 'nullable|in:0,1',
            'sort' => 'nullable'
        ]);
        if ($params['protocol'] == 'anytls' && (int) $params['tls'] === 0) {
            $params['tls'] = 1;
        }
        if (in_array($params['protocol'], ['hysteria2', 'trojan', 'tuic'])) {
            $params['tls'] = 1;
        }
        $params['tls_settings'] = $this->normalizeTlsSettingsForProtocol(
            $params['protocol'],
            $params['tls_settings'] ?? [],
            (int) ($params['tls'] ?? 0)
        );
        if (isset($params['tls']) && (int)$params['tls'] === 2) {
            $keyPair = SodiumCompat::crypto_box_keypair();
            if (empty($params['tls_settings']['public_key'])) {
                $params['tls_settings']['public_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_publickey($keyPair));
            }
            if (empty($params['tls_settings']['private_key'])) {
                $params['tls_settings']['private_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_secretkey($keyPair));
            }
            if (empty($params['tls_settings']['short_id'])) {
                $params['tls_settings']['short_id'] = substr(sha1($params['tls_settings']['private_key']), 0, 8);
            }
            if (empty($params['tls_settings']['server_port'])) {
                $params['tls_settings']['server_port'] = "443";
            } else {
                $params['tls_settings']['server_port'] = (string) $params['tls_settings']['server_port'];
            }
        }
        if (isset($params['tls_settings']) && !empty($params['tls_settings']['ech']) && $params['tls_settings']['ech'] === 'custom') {
            if (empty($params['tls_settings']['ech_server_name'])) {
                $params['tls_settings']['ech'] = '';
            } else {
                $outerSni = $params['tls_settings']['ech_server_name'];
                if (empty($params['tls_settings']['ech_key']) || empty($params['tls_settings']['ech_config'])) {
                    $echPair = Helper::generateEchKeyPair($outerSni);
                    if (empty($params['tls_settings']['ech_key'])) {
                        $params['tls_settings']['ech_key'] = $echPair['ech_key'];
                    }
                    if (empty($params['tls_settings']['ech_config'])) {
                        $params['tls_settings']['ech_config'] = $echPair['ech_config'];
                    }
                }
            }
        }
        if (isset($params['network_settings'])) {
            $ns = $params['network_settings'];
            if (isset($ns['acceptProxyProtocol'])) {
                $ns['acceptProxyProtocol'] = filter_var($ns['acceptProxyProtocol'], FILTER_VALIDATE_BOOLEAN);
            }
            $params['network_settings'] = $ns;
        }
        if ($params['network'] != 'tcp' && isset($params['encryption']) && $params['encryption'] != 'mlkem768x25519plus') {
            $params['flow'] = null;
        }
        if ($params['network'] == 'xhttp' && isset($params['network_settings'])) {
            $ns = $params['network_settings'];
            if (isset($ns['extra']) && is_array($ns['extra'])) {
                $extra = $ns['extra'];
                if (isset($extra['xPaddingObfsMode'])) {
                    $extra['xPaddingObfsMode'] = filter_var($extra['xPaddingObfsMode'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['noGRPCHeader'])) {
                    $extra['noGRPCHeader'] = filter_var($extra['noGRPCHeader'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['noSSEHeader'])) {
                    $extra['noSSEHeader'] = filter_var($extra['noSSEHeader'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['scMaxBufferedPosts'])) {
                    $extra['scMaxBufferedPosts'] = (int)$extra['scMaxBufferedPosts'];
                }
                if (isset($extra['xmux']) && is_array($extra['xmux'])) {
                    $xmux = $extra['xmux'];
                    if (isset($xmux['hKeepAlivePeriod'])) {
                        $xmux['hKeepAlivePeriod'] = (int)$xmux['hKeepAlivePeriod'];
                    }
                    $extra['xmux'] = $xmux;
                }
                if (isset($extra['downloadSettings']) && is_array($extra['downloadSettings'])) {
                    $downloadSettings = $extra['downloadSettings'];
                    if (isset($downloadSettings['port'])) {
                        $downloadSettings['port'] = (int)$downloadSettings['port'];
                    }
                    $extra['downloadSettings'] = $downloadSettings;
                }
                $ns['extra'] = $extra;
            }
            $params['network_settings'] = $ns;
        }
        if (isset($params['encryption']) && $params['encryption'] == 'mlkem768x25519plus') {
            $keyPair = SodiumCompat::crypto_box_keypair();
            $params['encryption_settings'] = $params['encryption_settings'] ?? [];
            if (!isset($params['encryption_settings']['mode'])) {
                $params['encryption_settings']['mode'] = 'native';
            }
            if (isset($params['encryption_settings']['rtt'])) {
                if ($params['encryption_settings']['rtt'] == '1rtt') {
                    $params['encryption_settings']['ticket'] = '0s';
                }
            } else {
                $params['encryption_settings']['rtt'] = '0rtt';
                $params['encryption_settings']['ticket'] = '600s';
            }
            if (empty($params['encryption_settings']['private_key'])) {
                $params['encryption_settings']['private_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_secretkey($keyPair));
            }
            if (empty($params['encryption_settings']['password'])) {
                $params['encryption_settings']['password'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_publickey($keyPair));
            }
        }

        if (isset($params['padding_scheme'])) {
            $params['padding_scheme'] = json_decode($params['padding_scheme']);
        }

        if (!isset($params['up_mbps'])) {
            $params['up_mbps'] = 0;
        }
        if (!isset($params['down_mbps'])) {
            $params['down_mbps'] = 0;
        }

        if(isset($params['obfs'])) {
            if(!isset($params['obfs_password']))  $params['obfs_password'] = Helper::getServerKey($request->input('created_at'), 16);
        } else {
            $params['obfs_password'] = null;
        }

        if($params['protocol'] == 'shadowsocks' && !isset($params['cipher'])) {
            $params['cipher'] = 'aes-128-gcm';
        }
        if (array_key_exists('machine_id', $params)) {
            $params['machine_id'] = !empty($params['machine_id']) ? (int) $params['machine_id'] : null;
        }
        if (array_key_exists('relay_machine_id', $params)) {
            $params['relay_machine_id'] = !empty($params['relay_machine_id']) ? (int) $params['relay_machine_id'] : null;
        }
        $inputMachineId = array_key_exists('machine_id', $params) ? (int) ($params['machine_id'] ?: 0) : 0;
        if (
            !empty($params['relay_machine_id']) &&
            $inputMachineId > 0 &&
            (int) $params['relay_machine_id'] === $inputMachineId
        ) {
            $params['relay_machine_id'] = null;
        }

        if ($request->input('id')) {
            $server = ServerV2node::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            $originalMachineId = (int) ($server->machine_id ?: 0);
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }

            $this->notifyMachineNodesChanged([
                $originalMachineId,
                (int) ($server->machine_id ?: 0),
                (int) ($server->relay_machine_id ?: 0),
            ]);
            return response([
                'data' => true
            ]);
        }

        $server = ServerV2node::create($params);
        if (!$server) {
            abort(500, '创建失败');
        }

        $this->notifyMachineNodesChanged([
            (int) ($server->machine_id ?: 0),
            (int) ($server->relay_machine_id ?: 0),
        ]);
        return response([
            'data' => true
        ]);
    }

    private function normalizeTlsSettingsForProtocol(string $protocol, array $settings, int $tlsMode = 0): array
    {
        if (!in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'], true)) {
            return $settings;
        }

        if (($tlsMode === 1 || $tlsMode === 2) && empty($settings['server_name'])) {
            $settings['server_name'] = self::DEFAULT_TLS_SERVER_NAME;
        }

        if (
            $tlsMode === 1 &&
            isset($settings['cert_mode']) &&
            strtolower((string) $settings['cert_mode']) === 'self'
        ) {
            $settings['allow_insecure'] = 1;
        }

        if ($tlsMode === 2 && empty($settings['server_port'])) {
            $settings['server_port'] = "443";
        } elseif (array_key_exists('server_port', $settings) && $settings['server_port'] !== null && $settings['server_port'] !== '') {
            $settings['server_port'] = (string) $settings['server_port'];
        }

        return $settings;
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerV2node::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        $machineId = (int) ($server->machine_id ?: 0);
        $relayMachineId = (int) ($server->relay_machine_id ?: 0);
        $result = $server->delete();
        $this->notifyMachineNodesChanged([$machineId, $relayMachineId]);
        return response([
            'data' => $result
        ]);
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'show' => 'nullable|in:0,1',
            'machine_id' => 'nullable|integer|exists:v2_machine,id',
            'relay_machine_id' => 'nullable|integer|exists:v2_machine,id',
        ]);

        $server = ServerV2node::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        $originalMachineId = (int) ($server->machine_id ?: 0);
        if (array_key_exists('machine_id', $params)) {
            $params['machine_id'] = !empty($params['machine_id']) ? (int) $params['machine_id'] : null;
        }
        if (array_key_exists('relay_machine_id', $params)) {
            $params['relay_machine_id'] = !empty($params['relay_machine_id']) ? (int) $params['relay_machine_id'] : null;
        }
        $nextMachineId = array_key_exists('machine_id', $params)
            ? (int) ($params['machine_id'] ?: 0)
            : $originalMachineId;
        if (
            !empty($params['relay_machine_id']) &&
            $nextMachineId > 0 &&
            (int) $params['relay_machine_id'] === $nextMachineId
        ) {
            $params['relay_machine_id'] = null;
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }
        $this->notifyMachineNodesChanged([
            $originalMachineId,
            (int) ($server->machine_id ?: 0),
            (int) ($server->relay_machine_id ?: 0),
        ]);
        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerV2node::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, '服务器不存在');
        }
        $copiedServer = ServerV2node::create($server->toArray());
        if (!$copiedServer) {
            abort(500, '复制失败');
        }

        $this->notifyMachineNodesChanged([
            (int) ($copiedServer->machine_id ?: 0),
            (int) ($copiedServer->relay_machine_id ?: 0),
        ]);

        return response([
            'data' => true
        ]);
    }
}
