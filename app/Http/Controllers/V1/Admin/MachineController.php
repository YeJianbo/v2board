<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\ServerGroup;
use App\Models\ServerV2node;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    private const INSTALL_TOKEN_TTL_SECONDS = 604800;
    private const ONLINE_WINDOW_SECONDS = 180;
    private const RESTART_TOKEN_TTL_SECONDS = 300;

    private function normalizeRelayRules($relayRules): array
    {
        if ($relayRules === null || $relayRules === '') {
            return [];
        }

        if (!is_array($relayRules)) {
            throw ValidationException::withMessages([
                'relay_rules' => '转发规则格式不正确',
            ]);
        }

        $normalizedRules = [];

        foreach ($relayRules as $index => $rule) {
            if (!is_array($rule)) {
                throw ValidationException::withMessages([
                    "relay_rules.{$index}" => '转发规则必须是对象',
                ]);
            }

            $listenHost = trim((string) ($rule['listen_host'] ?? '0.0.0.0'));
            $targetHost = trim((string) ($rule['target_host'] ?? ''));
            $remark = trim((string) ($rule['remark'] ?? ($rule['name'] ?? '')));
            $listenPort = (int) ($rule['listen_port'] ?? 0);
            $targetPort = (int) ($rule['target_port'] ?? 0);
            $protocols = is_array($rule['protocols'] ?? null) ? $rule['protocols'] : [];

            $protocols = array_values(array_unique(array_filter(array_map(function ($protocol) {
                return strtolower(trim((string) $protocol));
            }, $protocols), function ($protocol) {
                return in_array($protocol, ['tcp', 'udp'], true);
            })));

            if ($listenHost === '') {
                $listenHost = '0.0.0.0';
            }

            if ($targetHost === '') {
                throw ValidationException::withMessages([
                    "relay_rules.{$index}.target_host" => '转发目标地址不能为空',
                ]);
            }

            if ($listenPort < 1 || $listenPort > 65535) {
                throw ValidationException::withMessages([
                    "relay_rules.{$index}.listen_port" => '监听端口必须在 1-65535 之间',
                ]);
            }

            if ($targetPort < 1 || $targetPort > 65535) {
                throw ValidationException::withMessages([
                    "relay_rules.{$index}.target_port" => '目标端口必须在 1-65535 之间',
                ]);
            }

            if (!$protocols) {
                throw ValidationException::withMessages([
                    "relay_rules.{$index}.protocols" => '至少选择一个传输协议',
                ]);
            }

            $normalizedRules[] = [
                'listen_host' => $listenHost,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'protocols' => $protocols,
                'remark' => $remark,
            ];
        }

        return $normalizedRules;
    }

    public function fetch(Request $request)
    {
        return response([
            'data' => Machine::orderBy('id', 'DESC')->get()->map(function (Machine $machine) {
                $status = $this->decodeStatus($machine->status);
                $lastSeenAt = $this->resolveLastSeenAt($machine, $status);
                $isOnline = $lastSeenAt > 0 && (time() - $lastSeenAt) < self::ONLINE_WINDOW_SECONDS;
                $reportedIp = $this->resolveReportedIp($status);
                $ddnsHost = $this->resolveDdnsHost($machine);
                $displayHost = $this->resolveDisplayHost($machine->host, $status, $ddnsHost);
                $countryCode = strtoupper((string) ($status['country_code'] ?? ''));
                $country = (string) ($status['country'] ?? '');
                $ddnsLastSyncedAt = (int) ($status['ddns_synced_at'] ?? 0);
                $ddnsLastSyncedIp = trim((string) ($status['ddns_synced_ip'] ?? ''));
                $ddnsError = trim((string) ($status['ddns_error'] ?? ''));

                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'host' => $displayHost,
                    'configured_host' => $machine->host,
                    'display_host' => $displayHost,
                    'connect_host' => $displayHost ?: ($reportedIp ?: (string) $machine->host),
                    'reported_ip' => $reportedIp,
                    'api_token' => $machine->api_token,
                    'status' => $machine->status,
                    'status_data' => $status,
                    'country_code' => $countryCode,
                    'country' => $country,
                    'ddns_enabled' => (int) ($machine->ddns_enabled ? 1 : 0),
                    'ddns_provider' => (string) ($machine->ddns_provider ?: 'cloudflare'),
                    'ddns_zone_name' => (string) ($machine->ddns_zone_name ?: ''),
                    'ddns_record_name' => (string) ($machine->ddns_record_name ?: ''),
                    'ddns_record_type' => (string) ($machine->ddns_record_type ?: 'A'),
                    'ddns_ttl' => (int) ($machine->ddns_ttl ?: 120),
                    'ddns_proxied' => (int) ($machine->ddns_proxied ? 1 : 0),
                    'ddns_has_api_token' => $this->machineHasDdnsApiToken($machine) ? 1 : 0,
                    'ddns_host' => $ddnsHost,
                    'ddns_last_synced_ip' => $ddnsLastSyncedIp,
                    'ddns_last_synced_at' => $ddnsLastSyncedAt,
                    'ddns_error' => $ddnsError,
                    'relay_rules' => $machine->relay_rules ?: [],
                    'is_online' => $isOnline ? 1 : 0,
                    'last_seen_at' => $lastSeenAt,
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                ];
            })->values()
        ]);
    }

    private function decodeStatus($rawStatus): ?array
    {
        if (empty($rawStatus)) {
            return null;
        }

        $decoded = json_decode((string) $rawStatus, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function resolveLastSeenAt(Machine $machine, ?array $status): int
    {
        $reportedAt = (int) ($status['reported_at'] ?? 0);
        if ($reportedAt > 0) {
            return $reportedAt;
        }

        return (int) ($machine->updated_at ?? 0);
    }

    private function resolveReportedIp(?array $status): string
    {
        foreach (['primary_ip', 'remote_ip', 'ip'] as $key) {
            $value = trim((string) ($status[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveDisplayHost(?string $configuredHost, ?array $status, ?string $ddnsHost = null): string
    {
        $configuredHost = trim((string) $configuredHost);
        $ddnsHost = trim((string) $ddnsHost);
        $reportedIp = $this->resolveReportedIp($status);

        if ($ddnsHost !== '') {
            return $ddnsHost;
        }

        if ($configuredHost === '') {
            return $reportedIp;
        }

        if (
            $reportedIp !== '' &&
            $this->isPrivateIp($configuredHost) &&
            !$this->isPrivateIp($reportedIp)
        ) {
            return $reportedIp;
        }

        return $configuredHost;
    }

    private function resolveDdnsHost(Machine $machine): string
    {
        $recordName = trim((string) $machine->ddns_record_name);
        $zoneName = trim((string) $machine->ddns_zone_name);

        if ($recordName === '') {
            return '';
        }

        if ($recordName === '@') {
            return $zoneName;
        }

        if ($zoneName !== '' && preg_match('/(^|\.)' . preg_quote($zoneName, '/') . '$/i', $recordName)) {
            return $recordName;
        }

        return $zoneName !== '' ? "{$recordName}.{$zoneName}" : $recordName;
    }

    private function machineHasDdnsApiToken(Machine $machine): bool
    {
        return trim((string) $machine->ddns_api_token) !== '';
    }

    private function encryptMachineDdnsApiToken(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : Crypt::encryptString($value);
    }

    private function notifyMachineNodeSetChanged(int $machineId, bool $queueRestart = true): void
    {
        NodeSyncService::notifyMachineNodesChanged($machineId);

        if ($queueRestart) {
            Cache::put(
                'v2node_probe_restart:' . $machineId,
                (string) time() . '-' . Str::random(12),
                self::RESTART_TOKEN_TTL_SECONDS
            );
        }
    }

    private function isPrivateIp(?string $ip): bool
    {
        $ip = trim((string) $ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string|max:100',
            'host' => 'nullable|string|max:255',
            'ddns_enabled' => 'nullable|boolean',
            'ddns_provider' => 'nullable|string|in:cloudflare',
            'ddns_zone_name' => 'nullable|string|max:191',
            'ddns_record_name' => 'nullable|string|max:191',
            'ddns_record_type' => 'nullable|string|in:A,AAAA',
            'ddns_ttl' => 'nullable|integer|min:120|max:86400',
            'ddns_proxied' => 'nullable|boolean',
            'ddns_api_token' => 'nullable|string|max:4096',
            'relay_rules' => 'nullable|array',
        ]);

        $params['host'] = trim((string) ($params['host'] ?? ''));
        $params['ddns_enabled'] = (int) ($request->boolean('ddns_enabled'));
        $params['ddns_provider'] = $params['ddns_enabled'] ? ($params['ddns_provider'] ?? 'cloudflare') : null;
        $params['ddns_zone_name'] = trim((string) ($params['ddns_zone_name'] ?? ''));
        $params['ddns_record_name'] = trim((string) ($params['ddns_record_name'] ?? ''));
        $params['ddns_record_type'] = strtoupper((string) ($params['ddns_record_type'] ?? 'A')) ?: 'A';
        $params['ddns_ttl'] = (int) ($params['ddns_ttl'] ?? 120);
        $params['ddns_proxied'] = (int) ($request->boolean('ddns_proxied'));
        $params['relay_rules'] = $this->normalizeRelayRules($request->input('relay_rules', []));
        $incomingDdnsApiToken = trim((string) ($params['ddns_api_token'] ?? ''));
        unset($params['ddns_api_token']);

        if ($request->input('id')) {
            $machine = Machine::findOrFail($request->input('id'));
            if ($incomingDdnsApiToken !== '') {
                $params['ddns_api_token'] = $this->encryptMachineDdnsApiToken($incomingDdnsApiToken);
            }
            $machine->update($params);
        } else {
            $params['api_token'] = Str::random(32);
            $params['ddns_api_token'] = $this->encryptMachineDdnsApiToken($incomingDdnsApiToken);
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

    public function installToken(Request $request)
    {
        $token = Str::random(48);
        Cache::put('v2node_probe_enroll:' . hash('sha256', $token), true, self::INSTALL_TOKEN_TTL_SECONDS);

        return response([
            'data' => [
                'token' => $token,
                'expires_in' => self::INSTALL_TOKEN_TTL_SECONDS,
                'expires_at' => time() + self::INSTALL_TOKEN_TTL_SECONDS,
            ],
        ]);
    }

    public function createV2node(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_machine,id',
            'relay_machine_id' => 'nullable|integer|exists:v2_machine,id',
            'name' => 'required|string',
            'host' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
            'server_port' => 'nullable|integer|min:1|max:65535',
            'protocol' => 'nullable|in:shadowsocks,vmess,vless,trojan,tuic,hysteria2,anytls',
            'group_id' => 'nullable|array',
            'route_id' => 'nullable|array',
            'rate' => 'nullable|numeric',
            'show' => 'nullable|in:0,1',
            'tls_settings' => 'nullable|array',
            'tls' => 'nullable|in:0,1,2',
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

        $protocol = $params['protocol'] ?? 'anytls';
        $port = $params['port'] ?? random_int(20000, 60000);
        $serverPort = $params['server_port'] ?? $port;
        $tlsSettings = $this->defaultTlsSettingsForProtocol(
            $protocol,
            $params['tls_settings'] ?? []
        );

        $server = ServerV2node::create([
            'group_id' => array_values(array_map('intval', $groupIds)),
            'route_id' => array_values(array_map('intval', $params['route_id'] ?? [])),
            'name' => $params['name'],
            'parent_id' => null,
            'machine_id' => $machine->id,
            'relay_machine_id' => !empty($params['relay_machine_id']) && (int) $params['relay_machine_id'] !== (int) $machine->id
                ? (int) $params['relay_machine_id']
                : null,
            'host' => $params['host'] ?? $machine->host ?: '127.0.0.1',
            'listen_ip' => '0.0.0.0',
            'port' => $port,
            'server_port' => $serverPort,
            'tags' => [],
            'rate' => $params['rate'] ?? 1,
            'show' => $params['show'] ?? 1,
            'sort' => null,
            'protocol' => $protocol,
            'tls' => $params['tls'] ?? ($protocol === 'anytls' ? 1 : 0),
            'tls_settings' => $tlsSettings,
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

        $this->notifyMachineNodeSetChanged((int) $machine->id);
        if (!empty($server->relay_machine_id)) {
            $this->notifyMachineNodeSetChanged((int) $server->relay_machine_id, false);
        }

        return response([
            'data' => [
                'id' => $server->id,
                'machine_id' => $machine->id,
                'relay_machine_id' => (int) ($server->relay_machine_id ?: 0),
            ],
        ]);
    }

    private function defaultTlsSettingsForProtocol(string $protocol, array $settings = []): array
    {
        if (!in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'], true)) {
            return $settings;
        }

        if (empty($settings['server_name'])) {
            $settings['server_name'] = 'genshin.hoyoverse.com';
        }

        return $settings;
    }

    // Generate deploy command for the specific machine
    public function deployCommand(Request $request)
    {
        abort(410, '通用远程命令下发已禁用；探针只支持配置同步、DDNS、端口转发、v2node 服务重启和固定网络优化');
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

    public function enableBbr(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
        ]);

        $machine = Machine::findOrFail($params['id']);
        $status = $this->decodeStatus($machine->status) ?: [];
        $virtualization = strtolower(trim((string) ($status['virtualization'] ?? '')));
        if ($virtualization !== 'kvm') {
            abort(422, 'BBR 仅对 KVM 虚拟化机器开放');
        }

        $enableToken = (string) time() . '-' . Str::random(12);

        Cache::put(
            'v2node_probe_enable_bbr:' . $machine->id,
            $enableToken,
            300
        );

        return response([
            'data' => [
                'enable_bbr_token' => $enableToken,
            ],
            'message' => 'BBR 启用指令已下发',
        ]);
    }
}
