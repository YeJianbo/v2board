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
    private const INSTALL_TOKEN_MAX_USES = 100;
    private const ONLINE_WINDOW_SECONDS = 180;
    private const RESTART_TOKEN_TTL_SECONDS = 300;
    private const PROBE_AUTO_UPDATE_INTERVAL_SECONDS = 86400;
    private const ADMIN_FETCH_CACHE_SECONDS = 2;

    private function probeCache()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store(config('cache.default', 'file'));
        }
    }

    private function forgetAdminFetchCache(): void
    {
        Machine::forgetAdminFetchCache();
    }

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

            $listenHost = trim((string) ($rule['listen_host'] ?? $rule['listenHost'] ?? $rule['local_host'] ?? $rule['localHost'] ?? '0.0.0.0'));
            $targetHost = trim((string) ($rule['target_host'] ?? $rule['targetHost'] ?? $rule['remote_host'] ?? $rule['remoteHost'] ?? $rule['host'] ?? ''));
            $remark = trim((string) ($rule['remark'] ?? ($rule['name'] ?? '')));
            $listenPort = (int) ($rule['listen_port'] ?? $rule['listenPort'] ?? $rule['local_port'] ?? $rule['localPort'] ?? 0);
            $targetPort = (int) ($rule['target_port'] ?? $rule['targetPort'] ?? $rule['remote_port'] ?? $rule['remotePort'] ?? $rule['port'] ?? 0);
            $protocols = $this->normalizeRelayRuleProtocols($rule);

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

    private function normalizeRelayRuleProtocols(array $rule): array
    {
        $rawProtocols = $rule['protocols'] ?? null;
        if ($rawProtocols === null || $rawProtocols === '' || (is_array($rawProtocols) && !$rawProtocols)) {
            $rawProtocols = $rule['protocol'] ?? ($rule['type'] ?? []);
        }

        if (is_string($rawProtocols)) {
            $rawProtocols = preg_split('/[\s,;\/|+]+/', $rawProtocols) ?: [];
        } elseif (!is_array($rawProtocols)) {
            $rawProtocols = [$rawProtocols];
        }

        $protocols = [];
        foreach ($rawProtocols as $protocol) {
            $normalized = strtolower(trim((string) $protocol));
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, ['all', 'both', 'tcpudp'], true)) {
                $protocols[] = 'tcp';
                $protocols[] = 'udp';
                continue;
            }
            if (in_array($normalized, ['tcp', 'udp'], true)) {
                $protocols[] = $normalized;
            }
        }

        return array_values(array_unique($protocols));
    }

    private function resolveMachineConnectHost(Machine $machine): string
    {
        $status = $this->decodeStatus($machine->status, $machine) ?: [];

        foreach (['primary_ip', 'public_ipv4', 'remote_ip', 'ip'] as $key) {
            $value = trim((string) ($status[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) $machine->host);
    }

    private function resolveV2nodeRelayProtocols(ServerV2node $server): array
    {
        $protocol = strtolower(trim((string) $server->protocol));
        $network = strtolower(trim((string) $server->network));

        if (in_array($protocol, ['hysteria2', 'tuic'], true)) {
            return ['udp'];
        }

        if ($protocol === 'shadowsocks') {
            $networkSettings = is_array($server->network_settings) ? $server->network_settings : [];
            $hasHttpObfs = !empty($networkSettings['path']) || !empty($networkSettings['host']);
            return $hasHttpObfs ? ['tcp'] : ['tcp', 'udp'];
        }

        if (in_array($network, ['udp', 'quic', 'kcp', 'mkcp'], true)) {
            return ['udp'];
        }

        return ['tcp'];
    }

    private function buildGeneratedRelayRules(Machine $machine): array
    {
        $servers = ServerV2node::query()
            ->where('relay_machine_id', $machine->id)
            ->where('machine_id', '!=', $machine->id)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        if ($servers->isEmpty()) {
            return [];
        }

        $parentServers = ServerV2node::query()
            ->whereIn('id', $servers->pluck('parent_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->get()
            ->keyBy('id');
        $manualOverrideMap = $this->buildRelayRuleOverrideMap(is_array($machine->relay_rules) ? $machine->relay_rules : []);

        return $servers->map(function (ServerV2node $server) use ($parentServers, $manualOverrideMap) {
            $listenHost = '0.0.0.0';

            $listenPort = (int) $server->port;
            if ($listenPort < 1 || $listenPort > 65535) {
                $listenPort = (int) $server->server_port;
            }

            $targetPort = (int) $server->server_port;
            if ($listenPort < 1 || $listenPort > 65535 || $targetPort < 1 || $targetPort > 65535) {
                return null;
            }

            $targetHost = $this->resolveRelayTargetHostFromServer($server, $parentServers);
            if ($targetHost === '') {
                return null;
            }

            $protocols = $this->filterAutoRelayProtocolsByManualOverrides(
                $listenHost,
                $listenPort,
                $this->resolveV2nodeRelayProtocols($server),
                $manualOverrideMap
            );
            if (!$protocols) {
                return null;
            }

            return [
                'id' => 'generated-v2node-' . $server->id,
                'source' => 'v2node',
                'source_node_id' => (int) $server->id,
                'source_node_name' => (string) $server->name,
                'listen_host' => $listenHost,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'protocols' => $protocols,
                'remark' => trim((string) $server->name) ?: ('v2node-' . $server->id),
                'readonly' => 1,
            ];
        })->filter()->values()->all();
    }

    private function buildGeneratedRelayRulesByMachine($machines): array
    {
        $machineIds = $machines
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (!$machineIds) {
            return [];
        }

        $servers = ServerV2node::query()
            ->whereIn('relay_machine_id', $machineIds)
            ->whereNotNull('machine_id')
            ->whereColumn('machine_id', '!=', 'relay_machine_id')
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        if ($servers->isEmpty()) {
            return array_fill_keys($machineIds, []);
        }

        $parentIds = $servers
            ->pluck('parent_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $parentServers = $parentIds
            ? ServerV2node::query()->whereIn('id', $parentIds)->get()->keyBy('id')
            : collect();

        $manualOverrideMaps = [];
        foreach ($machines as $machine) {
            $machineId = (int) $machine->id;
            $manualOverrideMaps[$machineId] = $this->buildRelayRuleOverrideMap(
                is_array($machine->relay_rules) ? $machine->relay_rules : []
            );
        }

        $rulesByMachine = array_fill_keys($machineIds, []);
        foreach ($servers as $server) {
            $relayMachineId = (int) $server->relay_machine_id;
            $listenHost = '0.0.0.0';
            $listenPort = (int) $server->port;
            if ($listenPort < 1 || $listenPort > 65535) {
                $listenPort = (int) $server->server_port;
            }

            $targetPort = (int) $server->server_port;
            if ($listenPort < 1 || $listenPort > 65535 || $targetPort < 1 || $targetPort > 65535) {
                continue;
            }

            $targetHost = $this->resolveRelayTargetHostFromServer($server, $parentServers);
            if ($targetHost === '') {
                continue;
            }

            $protocols = $this->filterAutoRelayProtocolsByManualOverrides(
                $listenHost,
                $listenPort,
                $this->resolveV2nodeRelayProtocols($server),
                $manualOverrideMaps[$relayMachineId] ?? []
            );
            if (!$protocols) {
                continue;
            }

            $rulesByMachine[$relayMachineId][] = [
                'id' => 'generated-v2node-' . $server->id,
                'source' => 'v2node',
                'source_node_id' => (int) $server->id,
                'source_node_name' => (string) $server->name,
                'listen_host' => $listenHost,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'protocols' => $protocols,
                'remark' => trim((string) $server->name) ?: ('v2node-' . $server->id),
                'readonly' => 1,
            ];
        }

        return $rulesByMachine;
    }

    private function resolveRelayTargetHostFromServer(ServerV2node $server, $parentServers): string
    {
        $parentId = (int) ($server->parent_id ?: 0);
        if ($parentId > 0) {
            $parentServer = $parentServers->get($parentId);
            $parentHost = trim((string) ($parentServer->host ?? ''));
            if ($parentHost !== '') {
                return $parentHost;
            }
        }

        return trim((string) ($server->host ?? ''));
    }

    private function buildRelayRuleOverrideMap(array $rules): array
    {
        $map = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $listenHost = $this->normalizeRelayListenHostForOverride(
                $rule['listen_host'] ?? $rule['listenHost'] ?? $rule['local_host'] ?? $rule['localHost'] ?? '0.0.0.0'
            );
            $listenPort = (int) ($rule['listen_port'] ?? $rule['listenPort'] ?? $rule['local_port'] ?? $rule['localPort'] ?? 0);
            if ($listenPort < 1 || $listenPort > 65535) {
                continue;
            }

            $key = $listenHost . ':' . $listenPort;
            if (!isset($map[$key])) {
                $map[$key] = [];
            }

            $protocols = $this->normalizeRelayRuleProtocols($rule);
            foreach ($protocols as $protocol) {
                $normalizedProtocol = strtolower(trim((string) $protocol));
                if (in_array($normalizedProtocol, ['tcp', 'udp'], true)) {
                    $map[$key][$normalizedProtocol] = true;
                }
            }
        }

        return $map;
    }

    private function normalizeRelayListenHostForOverride($listenHost): string
    {
        $host = strtolower(trim((string) $listenHost));
        $host = trim($host, " \t\n\r\0\x0B[]");

        if ($host === '' || in_array($host, ['*', '0.0.0.0', '::', '::0', '::/0', ':::'], true)) {
            return '*';
        }

        return $host;
    }

    private function filterAutoRelayProtocolsByManualOverrides(string $listenHost, int $listenPort, array $protocols, array $overrideMap): array
    {
        $specificKey = $this->normalizeRelayListenHostForOverride($listenHost) . ':' . $listenPort;
        $wildcardKey = '*:' . $listenPort;
        $overridden = array_merge($overrideMap[$wildcardKey] ?? [], $overrideMap[$specificKey] ?? []);

        return array_values(array_filter(array_map(function ($protocol) {
            return strtolower(trim((string) $protocol));
        }, $protocols), function ($protocol) use ($overridden) {
            return $protocol !== '' && !isset($overridden[$protocol]);
        }));
    }

    public function fetch(Request $request)
    {
        $machines = Machine::query()
            ->select([
                'id',
                'name',
                'host',
                'status',
                'ddns_enabled',
                'ddns_provider',
                'ddns_zone_name',
                'ddns_record_name',
                'ddns_record_type',
                'ddns_ttl',
                'ddns_proxied',
                'relay_rules',
                'probe_auto_update',
                'created_at',
                'updated_at',
            ])
            ->selectRaw("CASE WHEN COALESCE(api_token, '') = '' THEN 0 ELSE 1 END AS api_token_present")
            ->selectRaw("CASE WHEN COALESCE(ddns_api_token, '') = '' THEN 0 ELSE 1 END AS ddns_api_token_present")
            ->orderBy('id', 'DESC')
            ->get();
        $generatedRelayRulesByMachine = $this->probeCache()->remember(
            Machine::ADMIN_GENERATED_RELAY_RULES_CACHE_KEY,
            self::ADMIN_FETCH_CACHE_SECONDS,
            function () use ($machines) {
                return $this->buildGeneratedRelayRulesByMachine($machines);
            }
        );

        $data = $machines->map(function (Machine $machine) use ($generatedRelayRulesByMachine) {
            $machineId = (int) $machine->id;
            $status = $this->decodeStatus($machine->status, $machine);
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
                'api_token_present' => (int) ($machine->api_token_present ?? 0),
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
                'ddns_has_api_token' => (int) ($machine->ddns_api_token_present ?? 0),
                'ddns_host' => $ddnsHost,
                'ddns_last_synced_ip' => $ddnsLastSyncedIp,
                'ddns_last_synced_at' => $ddnsLastSyncedAt,
                'ddns_error' => $ddnsError,
                'relay_rules' => $machine->relay_rules ?: [],
                'relay_rules_generated' => $generatedRelayRulesByMachine[$machineId] ?? [],
                'probe_auto_update' => (int) ($machine->probe_auto_update ? 1 : 0),
                'is_online' => $isOnline ? 1 : 0,
                'last_seen_at' => $lastSeenAt,
                'created_at' => $machine->created_at,
                'updated_at' => $machine->updated_at,
            ];
        })->values()->all();

        return response([
            'data' => $data,
        ]);
    }

    private function decodeStatus($rawStatus, ?Machine $machine = null): ?array
    {
        if ($machine) {
            $cached = $this->probeCache()->get($machine->statusCacheKey());
            if (is_array($cached)) {
                return $cached;
            }

            if (is_string($cached) && $cached !== '') {
                $decodedCached = json_decode($cached, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedCached)) {
                    return $decodedCached;
                }
            }
        }

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

    private function encryptMachineDdnsApiToken(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : Crypt::encryptString($value);
    }

    private function notifyMachineNodeSetChanged(int $machineId, bool $queueRestart = true): void
    {
        NodeSyncService::notifyMachineNodesChanged($machineId);

        if ($queueRestart) {
            $this->probeCache()->put(
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
            'probe_auto_update' => 'nullable|boolean',
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
        $params['probe_auto_update'] = (int) ($request->boolean('probe_auto_update'));
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
            $machine = Machine::create($params);
        }

        $this->probeCache()->forget($machine->probeAuthCacheKey());
        $this->forgetAdminFetchCache();

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $machine = Machine::findOrFail($request->input('id'));
        $machine->delete();
        $this->forgetAdminFetchCache();
        return response([
            'data' => true
        ]);
    }

    public function token(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
        ]);

        $machine = Machine::findOrFail($params['id']);
        if (empty($machine->api_token)) {
            $machine->api_token = Str::random(32);
            $machine->save();
        }

        return response([
            'data' => [
                'id' => (int) $machine->id,
                'api_token' => (string) $machine->api_token,
            ],
        ]);
    }

    public function installToken(Request $request)
    {
        $token = Str::random(48);
        $this->probeCache()->put('v2node_probe_enroll:' . hash('sha256', $token), [
            'uses' => 0,
            'max_uses' => self::INSTALL_TOKEN_MAX_USES,
            'created_at' => time(),
            'expires_at' => time() + self::INSTALL_TOKEN_TTL_SECONDS,
        ], self::INSTALL_TOKEN_TTL_SECONDS);

        return response([
            'data' => [
                'token' => $token,
                'expires_in' => self::INSTALL_TOKEN_TTL_SECONDS,
                'expires_at' => time() + self::INSTALL_TOKEN_TTL_SECONDS,
                'max_uses' => self::INSTALL_TOKEN_MAX_USES,
                'remaining_uses' => self::INSTALL_TOKEN_MAX_USES,
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
        $this->forgetAdminFetchCache();

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

        $this->probeCache()->put(
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
        $status = $this->decodeStatus($machine->status, $machine) ?: [];
        $virtualization = strtolower(trim((string) ($status['virtualization'] ?? '')));
        if ($virtualization !== 'kvm') {
            abort(422, 'BBR 仅对 KVM 虚拟化机器开放');
        }

        $enableToken = (string) time() . '-' . Str::random(12);

        $this->probeCache()->put(
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
