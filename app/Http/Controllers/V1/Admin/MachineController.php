<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineNetworkQuality;
use App\Models\MachineUpdateLog;
use App\Models\ServerGroup;
use App\Models\ServerV2node;
use App\Services\NodeSyncService;
use App\Services\MachineMetricService;
use App\Services\MachineRenewalService;
use App\Services\MachineRuntimeStreamService;
use App\Services\TelegramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Support\RavelConfig;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    private const INSTALL_TOKEN_TTL_SECONDS = 604800;
    private const INSTALL_TOKEN_MAX_USES = 100;
    private const ONLINE_WINDOW_SECONDS = 180;
    private const RESTART_TOKEN_TTL_SECONDS = 300;
    private const PROBE_AUTO_UPDATE_INTERVAL_SECONDS = 86400;
    private const PROBE_UPDATE_REQUEST_TTL_SECONDS = 3600;
    private const RUNTIME_TASK_TTL_SECONDS = 600;
    private const ADMIN_FETCH_CACHE_SECONDS = 2;
    private $probeCacheRepository = null;

    private function probeCache()
    {
        if ($this->probeCacheRepository !== null) {
            return $this->probeCacheRepository;
        }

        try {
            $cache = Cache::store('redis');
            $cache->get('machine:cache:connection-check');
            $this->probeCacheRepository = $cache;
        } catch (\Throwable $e) {
            $this->probeCacheRepository = Cache::store('file');
        }

        return $this->probeCacheRepository;
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

    private function expireStaleProbeUpdateStatus(Machine $machine, array $status): array
    {
        $actionStatus = strtolower(trim((string) ($status['probe_update_action_status'] ?? '')));
        if (!in_array($actionStatus, ['pending', 'running'], true)) {
            return $status;
        }

        $now = time();
        $cachedRequest = $this->probeCache()->get('v2node_probe_update:' . $machine->id);
        if (is_array($cachedRequest) && (int) ($cachedRequest['expires_at'] ?? 0) > $now) {
            return $status;
        }

        $requestId = trim((string) ($status['probe_update_request_id'] ?? ''));
        $actionAt = (int) ($status['probe_update_action_at'] ?? 0);
        $expiresAt = (int) ($status['probe_update_request_expires_at'] ?? 0);
        $deadline = $expiresAt > 0
            ? $expiresAt
            : ($actionAt > 0 ? $actionAt + self::PROBE_UPDATE_REQUEST_TTL_SECONDS : 0);
        if ($deadline > $now) {
            return $status;
        }

        $targetVersion = trim((string) (
            $status['probe_update_target_version']
            ?? $status['probe_update_requested_version']
            ?? 'latest'
        ));

        $error = '更新请求已过期，机器未在有效期内执行更新';
        $status['probe_update_action_status'] = 'failed';
        $status['probe_update_action_error'] = $error;
        unset(
            $status['probe_update_request_id'],
            $status['probe_update_requested_version'],
            $status['probe_update_request_expires_at']
        );

        $encodedStatus = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::table($machine->getTable())
            ->where('id', $machine->id)
            ->update(['status' => $encodedStatus]);
        $machine->status = $encodedStatus;
        $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);
        Machine::forgetPublicStatusCache();

        $historyRequestId = $requestId !== ''
            ? $requestId
            : 'legacy-' . $machine->id . '-' . ($actionAt > 0 ? $actionAt : $now);
        $updated = MachineUpdateLog::query()
            ->where('machine_id', $machine->id)
            ->where('request_id', $historyRequestId)
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'status' => 'failed',
                'error' => $error,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated === 0 && !MachineUpdateLog::query()
            ->where('machine_id', $machine->id)
            ->where('request_id', $historyRequestId)
            ->exists()
        ) {
            MachineUpdateLog::create([
                'machine_id' => (int) $machine->id,
                'request_id' => $historyRequestId,
                'source' => 'manual',
                'target_version' => mb_substr($targetVersion !== '' ? $targetVersion : 'latest', 0, 64),
                'status' => 'failed',
                'error' => $error,
                'requested_at' => $actionAt > 0 ? $actionAt : $now,
                'started_at' => $actionStatus === 'running' && $actionAt > 0 ? $actionAt : 0,
                'completed_at' => $now,
                'expires_at' => $deadline > 0 ? $deadline : $now,
            ]);
        }

        return $status;
    }

    public function fetch(Request $request)
    {
        $machines = Machine::query()
            ->with('machineGroup:id,name,sort')
            ->select([
                'id',
                'machine_group_id',
                'name',
                'host',
                'country_code',
                'country_name',
                'status',
                'sort',
                'public_visible',
                'traffic_alert_enabled',
                'traffic_alert_bytes',
                'traffic_alert_window',
                'traffic_reset_mode',
                'traffic_reset_day',
                'traffic_reset_hour',
                'traffic_reset_minute',
                'idc_name',
                'idc_url',
                'billing_amount',
                'billing_currency',
                'billing_cycle',
                'renew_at',
                'renew_alert_enabled',
                'renew_alert_days',
                'ddns_enabled',
                'ddns_provider',
                'ddns_zone_name',
                'ddns_record_name',
                'ddns_record_type',
                'ddns_ttl',
                'ddns_proxied',
                'relay_rules',
                'probe_auto_update',
                'maintenance_until',
                'maintenance_note',
                'network_quality_enabled',
                'network_quality_interval',
                'network_quality_targets',
                'created_at',
                'updated_at',
            ])
            ->selectRaw("CASE WHEN COALESCE(api_token, '') = '' THEN 0 ELSE 1 END AS api_token_present")
            ->selectRaw("CASE WHEN COALESCE(ddns_api_token, '') = '' THEN 0 ELSE 1 END AS ddns_api_token_present")
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        $generatedRelayRulesByMachine = $this->probeCache()->remember(
            Machine::ADMIN_GENERATED_RELAY_RULES_CACHE_KEY,
            self::ADMIN_FETCH_CACHE_SECONDS,
            function () use ($machines) {
                return $this->buildGeneratedRelayRulesByMachine($machines);
            }
        );

        $notificationService = app(TelegramNotificationService::class);
        $data = $machines->map(function (Machine $machine) use ($generatedRelayRulesByMachine, $notificationService) {
            $machineId = (int) $machine->id;
            $status = $this->expireStaleProbeUpdateStatus(
                $machine,
                $this->decodeStatus($machine->status, $machine) ?: []
            );
            $lastSeenAt = $this->resolveLastSeenAt($machine, $status);
            $isOnline = $lastSeenAt > 0 && (time() - $lastSeenAt) < self::ONLINE_WINDOW_SECONDS;
            $reportedIp = $this->resolveReportedIp($status);
            $ddnsHost = $this->resolveDdnsHost($machine);
            $displayHost = $this->resolveDisplayHost($machine->host, $status, $ddnsHost);
            $location = $machine->resolveLocation($status);
            $ddnsLastSyncedAt = (int) ($status['ddns_synced_at'] ?? 0);
            $ddnsLastSyncedIp = trim((string) ($status['ddns_synced_ip'] ?? ''));
            $ddnsError = trim((string) ($status['ddns_error'] ?? ''));
            $trafficUsage = $notificationService->getMachineTrafficState($machine);

            return [
                'id' => $machine->id,
                'name' => $machine->name,
                'machine_group_id' => $machine->machine_group_id ? (int) $machine->machine_group_id : null,
                'machine_group_name' => (string) ($machine->machineGroup->name ?? ''),
                'host' => $displayHost,
                'configured_host' => $machine->host,
                'display_host' => $displayHost,
                'connect_host' => $displayHost ?: ($reportedIp ?: (string) $machine->host),
                'reported_ip' => $reportedIp,
                'api_token_present' => (int) ($machine->api_token_present ?? 0),
                'status_data' => $status,
                'sort' => (int) $machine->sort,
                'public_visible' => (int) ($machine->public_visible ? 1 : 0),
                'traffic_alert_enabled' => (int) ($machine->traffic_alert_enabled ? 1 : 0),
                'traffic_alert_bytes' => (int) $machine->traffic_alert_bytes,
                'traffic_alert_window' => (int) ($machine->traffic_alert_window ?: 86400),
                'traffic_reset_mode' => (string) ($machine->traffic_reset_mode ?: 'rolling'),
                'traffic_reset_day' => (int) ($machine->traffic_reset_day ?: 1),
                'traffic_reset_hour' => (int) ($machine->traffic_reset_hour ?? 0),
                'traffic_reset_minute' => (int) ($machine->traffic_reset_minute ?? 0),
                'traffic_usage' => $trafficUsage,
                'idc_name' => (string) ($machine->idc_name ?: ''),
                'idc_url' => (string) ($machine->idc_url ?: ''),
                'billing_amount' => (string) ($machine->billing_amount ?: '0.00'),
                'billing_currency' => (string) ($machine->billing_currency ?: 'USD'),
                'billing_cycle' => (string) ($machine->billing_cycle ?: 'monthly'),
                'renew_at' => (int) ($machine->renew_at ?: 0),
                'renew_alert_enabled' => (int) ($machine->renew_alert_enabled ? 1 : 0),
                'renew_alert_days' => (int) ($machine->renew_alert_days ?? 7),
                'country_code' => $location['country_code'],
                'country_name' => $location['country_name'],
                'country' => $location['country_name'],
                'manual_country_code' => $location['manual_country_code'],
                'manual_country_name' => $location['manual_country_name'],
                'detected_country_code' => $location['detected_country_code'],
                'detected_country_name' => $location['detected_country_name'],
                'location_source' => $location['location_source'],
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
                'maintenance_until' => (int) ($machine->maintenance_until ?: 0),
                'maintenance_note' => (string) ($machine->maintenance_note ?: ''),
                'maintenance_active' => $machine->isInMaintenance() ? 1 : 0,
                'network_quality_enabled' => (int) ($machine->network_quality_enabled ? 1 : 0),
                'network_quality_interval' => (int) ($machine->network_quality_interval ?: 300),
                'network_quality_targets' => $machine->resolvedNetworkQualityTargets(),
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

    public function status(Request $request)
    {
        $notificationService = app(TelegramNotificationService::class);
        $machines = Machine::query()
            ->select([
                'id',
                'name',
                'status',
                'sort',
                'traffic_alert_bytes',
                'traffic_alert_window',
                'traffic_reset_mode',
                'traffic_reset_day',
                'traffic_reset_hour',
                'traffic_reset_minute',
                'updated_at',
            ])
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        $statusCacheKeys = $machines->mapWithKeys(function (Machine $machine) {
            return [(int) $machine->id => $machine->statusCacheKey()];
        })->all();
        try {
            $cachedStatuses = $statusCacheKeys
                ? $this->probeCache()->many(array_values($statusCacheKeys))
                : [];
        } catch (\Throwable $e) {
            $cachedStatuses = [];
        }
        $data = $machines
            ->map(function (Machine $machine) use ($notificationService, $statusCacheKeys, $cachedStatuses) {
                $cacheKey = $statusCacheKeys[(int) $machine->id] ?? '';
                $status = $this->decodeStatusPayload($cachedStatuses[$cacheKey] ?? $machine->status);
                $lastSeenAt = $this->resolveLastSeenAt($machine, $status);

                return [
                    'id' => (int) $machine->id,
                    'name' => (string) $machine->name,
                    'status_data' => $status,
                    'traffic_usage' => $notificationService->getMachineTrafficState($machine),
                    'is_online' => $lastSeenAt > 0 && (time() - $lastSeenAt) < self::ONLINE_WINDOW_SECONDS ? 1 : 0,
                    'last_seen_at' => $lastSeenAt,
                    'updated_at' => $machine->updated_at,
                ];
            })
            ->values()
            ->all();

        return response(['data' => $data]);
    }

    private function decodeStatus($rawStatus, ?Machine $machine = null): ?array
    {
        if ($machine) {
            $cached = $this->probeCache()->get($machine->statusCacheKey());
            if (is_array($cached)) {
                return $cached;
            }

            if (($decodedCached = $this->decodeStatusPayload($cached)) !== null) {
                return $decodedCached;
            }
        }

        return $this->decodeStatusPayload($rawStatus);
    }

    private function decodeStatusPayload($rawStatus): ?array
    {
        if (is_array($rawStatus)) {
            return $rawStatus;
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
            'id' => 'nullable|integer|exists:v2_machine,id',
            'name' => 'required|string|max:100',
            'host' => 'nullable|string|max:255',
            'machine_group_id' => 'nullable|integer|exists:v2_machine_group,id',
            'country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'country_name' => 'nullable|string|max:64',
            'public_visible' => 'nullable|boolean',
            'traffic_alert_enabled' => 'nullable|boolean',
            'traffic_alert_bytes' => 'nullable|integer|min:0|max:9007199254740991',
            'traffic_alert_window' => 'nullable|integer|min:300|max:2592000',
            'traffic_reset_mode' => 'nullable|string|in:rolling,daily,monthly',
            'traffic_reset_day' => 'nullable|integer|min:1|max:28',
            'traffic_reset_hour' => 'nullable|integer|min:0|max:23',
            'traffic_reset_minute' => 'nullable|integer|min:0|max:59',
            'idc_name' => 'nullable|string|max:100',
            'idc_url' => 'nullable|url|max:255',
            'billing_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'billing_currency' => 'nullable|string|regex:/^[A-Za-z]{3,8}$/',
            'billing_cycle' => 'nullable|string|in:monthly,quarterly,semiannual,yearly,custom',
            'renew_at' => 'nullable|integer|min:0|max:2147483647',
            'renew_alert_enabled' => 'nullable|boolean',
            'renew_alert_days' => 'nullable|integer|min:0|max:365',
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
            'maintenance_until' => 'nullable|integer|min:0|max:2147483647',
            'maintenance_note' => 'nullable|string|max:191',
            'network_quality_enabled' => 'nullable|boolean',
            'network_quality_interval' => 'nullable|integer|min:60|max:3600',
            'network_quality_targets' => 'nullable|array|min:1|max:8',
            'network_quality_targets.*.key' => 'nullable|string|max:32',
            'network_quality_targets.*.name' => 'required|string|max:64',
            'network_quality_targets.*.host' => 'required|string|max:191',
            'network_quality_targets.*.probe_type' => 'nullable|string|in:icmp,tcp',
            'network_quality_targets.*.port' => 'nullable|integer|min:1|max:65535',
            'network_quality_targets.*.ip_version' => 'nullable|string|in:auto,4,6',
            'network_quality_targets.*.enabled' => 'nullable|boolean',
        ]);

        $machineId = isset($params['id']) ? (int) $params['id'] : 0;
        unset($params['id']);
        $params['name'] = trim((string) $params['name']);
        if (array_key_exists('host', $params)) {
            $params['host'] = trim((string) $params['host']);
        }
        if (array_key_exists('machine_group_id', $params)) {
            $params['machine_group_id'] = (int) $params['machine_group_id'] ?: null;
        }
        if (array_key_exists('country_code', $params)) {
            $params['country_code'] = strtoupper(trim((string) $params['country_code'])) ?: null;
        }
        if (array_key_exists('country_name', $params)) {
            $params['country_name'] = trim((string) $params['country_name']) ?: null;
        }
        foreach (['public_visible', 'traffic_alert_enabled', 'renew_alert_enabled', 'ddns_enabled', 'ddns_proxied', 'probe_auto_update', 'network_quality_enabled'] as $field) {
            if ($request->has($field)) {
                $params[$field] = (int) $request->boolean($field);
            }
        }
        foreach (['ddns_zone_name', 'ddns_record_name'] as $field) {
            if (array_key_exists($field, $params)) {
                $params[$field] = trim((string) $params[$field]);
            }
        }
        if (array_key_exists('ddns_provider', $params)) {
            $params['ddns_provider'] = trim((string) $params['ddns_provider']) ?: 'cloudflare';
        }
        if (array_key_exists('ddns_record_type', $params)) {
            $params['ddns_record_type'] = strtoupper(trim((string) $params['ddns_record_type'])) ?: 'A';
        }
        if (array_key_exists('ddns_ttl', $params)) {
            $params['ddns_ttl'] = (int) $params['ddns_ttl'];
        }
        if ($request->has('relay_rules')) {
            $params['relay_rules'] = $this->normalizeRelayRules($request->input('relay_rules'));
        }
        if ($request->has('maintenance_until')) {
            $maintenanceUntil = (int) ($params['maintenance_until'] ?? 0);
            $params['maintenance_until'] = $maintenanceUntil > time() ? $maintenanceUntil : 0;
            $params['maintenance_note'] = $params['maintenance_until'] > 0
                ? trim((string) ($params['maintenance_note'] ?? ''))
                : null;
        } else {
            unset($params['maintenance_until'], $params['maintenance_note']);
        }
        if (array_key_exists('network_quality_interval', $params)) {
            $params['network_quality_interval'] = (int) $params['network_quality_interval'];
        }
        if ($request->has('network_quality_targets')) {
            $params['network_quality_targets'] = Machine::normalizeNetworkQualityTargets($params['network_quality_targets']);
        }
        if (array_key_exists('traffic_alert_bytes', $params)) {
            $params['traffic_alert_bytes'] = (int) $params['traffic_alert_bytes'];
        }
        if (array_key_exists('traffic_alert_window', $params)) {
            $params['traffic_alert_window'] = (int) $params['traffic_alert_window'];
        }
        foreach (['traffic_reset_day', 'traffic_reset_hour', 'traffic_reset_minute', 'renew_at', 'renew_alert_days'] as $field) {
            if (array_key_exists($field, $params)) {
                $params[$field] = (int) $params[$field];
            }
        }
        foreach (['idc_name', 'idc_url'] as $field) {
            if (array_key_exists($field, $params)) {
                $params[$field] = trim((string) $params[$field]) ?: null;
            }
        }
        if (array_key_exists('billing_currency', $params)) {
            $params['billing_currency'] = strtoupper(trim((string) $params['billing_currency'])) ?: 'USD';
        }
        if (array_key_exists('billing_amount', $params)) {
            $params['billing_amount'] = round((float) $params['billing_amount'], 2);
        }
        $incomingDdnsApiToken = trim((string) ($params['ddns_api_token'] ?? ''));
        unset($params['ddns_api_token']);

        if ($machineId > 0) {
            $machine = Machine::findOrFail($machineId);
            $alertEnabled = array_key_exists('traffic_alert_enabled', $params)
                ? (bool) $params['traffic_alert_enabled']
                : (bool) $machine->traffic_alert_enabled;
            $alertBytes = array_key_exists('traffic_alert_bytes', $params)
                ? (int) $params['traffic_alert_bytes']
                : (int) $machine->traffic_alert_bytes;
            if ($alertEnabled && $alertBytes <= 0) {
                throw ValidationException::withMessages([
                    'traffic_alert_bytes' => '开启流量告警时，流量阈值必须大于 0',
                ]);
            }
            $renewAlertEnabled = array_key_exists('renew_alert_enabled', $params)
                ? (bool) $params['renew_alert_enabled']
                : (bool) $machine->renew_alert_enabled;
            $renewAt = array_key_exists('renew_at', $params)
                ? (int) $params['renew_at']
                : (int) $machine->renew_at;
            if ($renewAlertEnabled && $renewAt <= 0) {
                throw ValidationException::withMessages([
                    'renew_at' => '开启续费提醒时，请先设置续费日期',
                ]);
            }
            if ($incomingDdnsApiToken !== '') {
                $params['ddns_api_token'] = $this->encryptMachineDdnsApiToken($incomingDdnsApiToken);
            }
            $machine->update($params);
        } else {
            if (!empty($params['traffic_alert_enabled']) && empty($params['traffic_alert_bytes'])) {
                throw ValidationException::withMessages([
                    'traffic_alert_bytes' => '开启流量告警时，流量阈值必须大于 0',
                ]);
            }
            if (!empty($params['renew_alert_enabled']) && empty($params['renew_at'])) {
                throw ValidationException::withMessages([
                    'renew_at' => '开启续费提醒时，请先设置续费日期',
                ]);
            }
            $params['api_token'] = Str::random(32);
            $params['ddns_api_token'] = $this->encryptMachineDdnsApiToken($incomingDdnsApiToken);
            $params['sort'] = ((int) Machine::max('sort')) + 1;
            $params['public_visible'] = $params['public_visible'] ?? 1;
            $params['traffic_alert_enabled'] = $params['traffic_alert_enabled'] ?? 0;
            $params['traffic_alert_bytes'] = $params['traffic_alert_bytes'] ?? 0;
            $params['traffic_alert_window'] = $params['traffic_alert_window'] ?? 86400;
            $params['traffic_reset_mode'] = $params['traffic_reset_mode'] ?? 'rolling';
            $params['traffic_reset_day'] = $params['traffic_reset_day'] ?? 1;
            $params['traffic_reset_hour'] = $params['traffic_reset_hour'] ?? 0;
            $params['traffic_reset_minute'] = $params['traffic_reset_minute'] ?? 0;
            $params['billing_amount'] = $params['billing_amount'] ?? 0;
            $params['billing_currency'] = $params['billing_currency'] ?? 'USD';
            $params['billing_cycle'] = $params['billing_cycle'] ?? 'monthly';
            $params['renew_at'] = $params['renew_at'] ?? 0;
            $params['renew_alert_enabled'] = $params['renew_alert_enabled'] ?? 0;
            $params['renew_alert_days'] = $params['renew_alert_days'] ?? 7;
            $params['probe_auto_update'] = $params['probe_auto_update'] ?? 1;
            $params['network_quality_enabled'] = $params['network_quality_enabled'] ?? 0;
            $params['network_quality_interval'] = $params['network_quality_interval'] ?? 300;
            $params['network_quality_targets'] = $params['network_quality_targets'] ?? Machine::DEFAULT_NETWORK_QUALITY_TARGETS;
            $machine = Machine::create($params);
        }

        $this->probeCache()->forget($machine->probeAuthCacheKey());
        $this->forgetAdminFetchCache();

        return response([
            'data' => true
        ]);
    }

    public function renew(Request $request, MachineRenewalService $renewalService)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'mode' => 'required|string|in:period,date',
            'period_unit' => 'nullable|required_if:mode,period|string|in:day,month,year',
            'period_value' => 'nullable|required_if:mode,period|integer|min:1|max:3650',
            'renew_at' => 'nullable|required_if:mode,date|integer|min:1|max:' . MachineRenewalService::MAX_RENEW_AT,
        ]);

        $result = DB::transaction(function () use ($params, $renewalService) {
            $machine = Machine::query()
                ->whereKey((int) $params['id'])
                ->lockForUpdate()
                ->firstOrFail();
            $previousRenewAt = max(0, (int) $machine->renew_at);

            try {
                if ($params['mode'] === 'date') {
                    $renewal = $renewalService->useTargetDate(
                        $previousRenewAt,
                        (int) $params['renew_at']
                    );
                } else {
                    $renewal = $renewalService->extend(
                        $previousRenewAt,
                        (string) $params['period_unit'],
                        (int) $params['period_value']
                    );
                }
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    $params['mode'] === 'date' ? 'renew_at' : 'period_value' => $exception->getMessage(),
                ]);
            }

            $machine->renew_at = (int) $renewal['renew_at'];
            $machine->save();

            return [
                'id' => (int) $machine->id,
                'name' => (string) $machine->name,
                'previous_renew_at' => $previousRenewAt,
                'base_at' => (int) $renewal['base_at'],
                'renew_at' => (int) $renewal['renew_at'],
                'billing_cycle' => (string) ($machine->billing_cycle ?: 'monthly'),
            ];
        });

        $this->forgetAdminFetchCache();

        return response([
            'data' => $result,
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

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|distinct|exists:v2_machine,id',
        ]);

        DB::transaction(function () use ($params) {
            foreach (array_values($params['ids']) as $index => $machineId) {
                Machine::query()
                    ->whereKey((int) $machineId)
                    ->update(['sort' => $index + 1]);
            }
        });

        $this->forgetAdminFetchCache();
        Machine::forgetPublicStatusCache();

        return response(['data' => true]);
    }

    public function networkQualityHistory(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_machine,id',
            'range_hours' => 'nullable|integer|in:1,6,24,168,720',
            'start_at' => 'nullable|integer|min:1|required_with:end_at',
            'end_at' => 'nullable|integer|min:1|required_with:start_at',
        ]);

        $endAt = isset($params['end_at']) ? min((int) $params['end_at'], time()) : time();
        $startedAt = isset($params['start_at']) ? (int) $params['start_at'] : now()->subHours((int) ($params['range_hours'] ?? 24))->timestamp;
        if ($startedAt >= $endAt) {
            throw ValidationException::withMessages(['start_at' => '开始时间必须早于结束时间']);
        }
        $rangeSeconds = $endAt - $startedAt;
        if ($rangeSeconds > 31 * 86400) {
            throw ValidationException::withMessages(['start_at' => '时间范围最长为 31 天']);
        }
        $bucketSeconds = match (true) {
            $rangeSeconds <= 3600 => 60,
            $rangeSeconds <= 21600 => 180,
            $rangeSeconds <= 86400 => 600,
            $rangeSeconds <= 604800 => 3600,
            default => 14400,
        };

        $records = MachineNetworkQuality::query()
            ->where('machine_id', (int) $params['machine_id'])
            ->where('recorded_at', '>=', $startedAt)
            ->where('recorded_at', '<=', $endAt)
            ->selectRaw(
                'target_key, probe_type, target_port, ip_version, MAX(target_name) AS target_name, target_host, '
                . 'SUM(sent) AS sent, SUM(received) AS received, AVG(packet_loss) AS packet_loss, '
                . 'MIN(latency_min) AS latency_min, AVG(latency_avg) AS latency_avg, '
                . 'MAX(latency_max) AS latency_max, MAX(recorded_at) AS recorded_at, '
                . 'FLOOR(recorded_at / ?) AS time_bucket',
                [$bucketSeconds]
            )
            ->groupBy('target_key', 'target_host', 'probe_type', 'target_port', 'ip_version', 'time_bucket')
            ->orderBy('recorded_at')
            ->get();

        return response([
            'data' => $records,
            'meta' => [
                'start_at' => $startedAt,
                'end_at' => $endAt,
                'bucket_seconds' => $bucketSeconds,
                'statistics' => $this->buildNetworkQualityStatistics(
                    (int) $params['machine_id'],
                    $endAt
                ),
            ],
        ]);
    }

    public function resourceHistory(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_machine,id',
            'range_hours' => 'nullable|integer|in:1,6,24,168,720',
        ]);
        $rangeHours = (int) ($params['range_hours'] ?? 24);
        $endAt = time();
        $startedAt = $endAt - $rangeHours * 3600;
        $metrics = app(MachineMetricService::class);
        $bucketSeconds = $metrics->bucketSeconds($endAt - $startedAt);
        $cacheKey = implode(':', [
            'admin',
            'machine',
            'resource_history',
            'v2',
            (int) $params['machine_id'],
            $rangeHours,
            intdiv($endAt, 30),
        ]);
        $historyStartedAt = hrtime(true);
        $records = $this->probeCache()->remember($cacheKey, 35, function () use (
            $metrics,
            $params,
            $startedAt,
            $endAt,
            $bucketSeconds
        ) {
            return $metrics->history(
                (int) $params['machine_id'],
                $startedAt,
                $endAt,
                $bucketSeconds
            );
        });
        $historyFetchMs = round((hrtime(true) - $historyStartedAt) / 1000000, 2);
        $annotations = $metrics->buildAnnotations(
            $records,
            $bucketSeconds,
            $this->machineMetricAlertConfiguration(),
            $endAt
        );

        return response([
            'data' => $records,
            'annotations' => $annotations,
            'meta' => [
                'start_at' => $startedAt,
                'end_at' => $endAt,
                'sample_interval_seconds' => MachineMetricService::SAMPLE_INTERVAL_SECONDS,
                'bucket_seconds' => $bucketSeconds,
                'retention_days' => MachineMetricService::RETENTION_DAYS,
                'hourly_retention_days' => MachineMetricService::HOURLY_RETENTION_DAYS,
                'history_fetch_ms' => $historyFetchMs,
                'storage' => $metrics->storageStats(),
            ],
        ]);
    }

    private function machineMetricAlertConfiguration(): array
    {
        $networkThresholdMbps = max(
            0,
            (float) admin_setting('telegram_machine_network_mbps_threshold', 0)
        );
        $networkEnabled = (bool) admin_setting('telegram_machine_network_alert_enable', 0)
            && $networkThresholdMbps > 0;

        return [
            'offline_seconds' => max(
                180,
                min(86400, (int) admin_setting('telegram_machine_offline_seconds', 300))
            ),
            'resource_enabled' => (bool) admin_setting('telegram_machine_resource_alert_enable', 1),
            'resource_duration_seconds' => max(
                60,
                min(86400, (int) admin_setting('telegram_machine_resource_duration_seconds', 180))
            ),
            'dynamic_network_spikes' => !$networkEnabled,
            'metrics' => [
                'cpu' => [
                    'enabled' => (bool) admin_setting('telegram_machine_cpu_alert_enable', 1),
                    'threshold' => max(1, min(100, (int) admin_setting('telegram_machine_cpu_threshold', 90))),
                    'label' => 'CPU',
                    'category' => 'usage',
                    'color' => '#e66a3f',
                    'unit' => '%',
                ],
                'memory' => [
                    'enabled' => (bool) admin_setting('telegram_machine_memory_alert_enable', 1),
                    'threshold' => max(1, min(100, (int) admin_setting('telegram_machine_memory_threshold', 90))),
                    'label' => '内存',
                    'category' => 'usage',
                    'color' => '#d97706',
                    'unit' => '%',
                ],
                'disk' => [
                    'enabled' => (bool) admin_setting('telegram_machine_disk_alert_enable', 1),
                    'threshold' => max(1, min(100, (int) admin_setting('telegram_machine_disk_threshold', 95))),
                    'label' => '磁盘',
                    'category' => 'usage',
                    'color' => '#dc2626',
                    'unit' => '%',
                ],
                'network' => [
                    'enabled' => $networkEnabled,
                    'threshold' => $networkThresholdMbps * 1000000 / 8,
                    'label' => '实时流量',
                    'category' => 'network',
                    'color' => '#f59e0b',
                    'unit' => 'B/s',
                ],
            ],
        ];
    }

    private function buildNetworkQualityStatistics(int $machineId, int $endAt): array
    {
        $cacheKey = 'admin:machine:network_quality_statistics:v1:' . $machineId . ':' . intdiv($endAt, 60);
        return $this->probeCache()->remember($cacheKey, 70, function () use ($machineId, $endAt) {
            return $this->calculateNetworkQualityStatistics($machineId, $endAt);
        });
    }

    private function calculateNetworkQualityStatistics(int $machineId, int $endAt): array
    {
        $machine = Machine::findOrFail($machineId);
        $interval = max(60, min(3600, (int) ($machine->network_quality_interval ?: 300)));
        $names = collect($machine->resolvedNetworkQualityTargets())
            ->mapWithKeys(fn ($target) => [(string) $target['key'] => (string) $target['name']]);
        $rows = MachineNetworkQuality::query()
            ->where('machine_id', $machineId)
            ->where('recorded_at', '>=', $endAt - 30 * 86400)
            ->where('recorded_at', '<=', $endAt)
            ->orderBy('target_key')
            ->orderBy('recorded_at')
            ->toBase()
            ->get(['target_key', 'target_name', 'sent', 'received', 'latency_avg', 'recorded_at']);

        $windows = ['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400];
        $result = [];
        foreach ($windows as $key => $seconds) {
            $grouped = $rows
                ->filter(fn ($row) => (int) $row->recorded_at >= $endAt - $seconds)
                ->groupBy('target_key');
            $result[$key] = $grouped->map(function ($targetRows, $targetKey) use ($interval, $names) {
                $sent = (int) $targetRows->sum('sent');
                $received = (int) $targetRows->sum('received');
                $latencies = $targetRows->pluck('latency_avg')
                    ->filter(fn ($value) => $value !== null && is_numeric($value))
                    ->map(fn ($value) => (float) $value)
                    ->sort()
                    ->values();
                $longestOutage = 0;
                $currentOutage = 0;
                foreach ($targetRows as $row) {
                    if ((int) $row->received === 0) {
                        $currentOutage += $interval;
                        $longestOutage = max($longestOutage, $currentOutage);
                    } else {
                        $currentOutage = 0;
                    }
                }
                $p95Index = $latencies->isEmpty()
                    ? null
                    : max(0, (int) ceil($latencies->count() * 0.95) - 1);

                return [
                    'target_key' => (string) $targetKey,
                    'target_name' => $names->get((string) $targetKey, (string) ($targetRows->last()->target_name ?? $targetKey)),
                    'availability' => $sent > 0 ? round($received / $sent * 100, 3) : null,
                    'average_latency' => $latencies->isEmpty() ? null : round((float) $latencies->avg(), 3),
                    'p95_latency' => $p95Index === null ? null : round((float) $latencies->get($p95Index), 3),
                    'longest_outage_seconds' => $longestOutage,
                    'sample_count' => $targetRows->count(),
                ];
            })->values()->all();
        }

        return $result;
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
        $params = $request->validate(array_merge([
            'machine_id' => 'required|integer|exists:v2_machine,id',
            'relay_machine_id' => 'nullable|integer|exists:v2_machine,id',
            'name' => 'required|string',
            'host' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
            'server_port' => 'nullable|integer|min:1|max:65535',
            'protocol' => 'nullable|in:shadowsocks,vmess,vless,trojan,tuic,hysteria2,anytls,ravel',
            'group_id' => 'nullable|array',
            'route_id' => 'nullable|array',
            'rate' => 'nullable|numeric',
            'show' => 'nullable|in:0,1',
            'tls_settings' => 'nullable|array',
            'tls' => 'nullable|in:0,1,2',
        ], RavelConfig::rules()));

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
        if ($protocol === 'ravel') {
            $params['tls'] = 1;
            $params['tls_settings']['server_name'] = $params['tls_settings']['server_name']
                ?? parse_url('https://' . $params['ravel_authority'], PHP_URL_HOST);
        }
        $ravelSettings = RavelConfig::normalize(
            $protocol,
            $params['ravel_settings'] ?? []
        );
        $port = $params['port'] ?? random_int(20000, 60000);
        $serverPort = $params['server_port'] ?? $port;
        $tlsSettings = $this->defaultTlsSettingsForProtocol(
            $protocol,
            $params['tls_settings'] ?? []
        );
        if ($protocol === 'anytls' && (int) ($params['tls'] ?? 1) === 1) {
            $tlsSettings = \App\Services\NodeTlsBootstrap::anytls($tlsSettings);
        }

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
            'tls' => $params['tls'] ?? (in_array($protocol, ['anytls', 'ravel'], true) ? 1 : 0),
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
            'ravel_authority' => $params['ravel_authority'] ?? null,
            'ravel_path' => $params['ravel_path'] ?? null,
            'ravel_gateway_group' => $params['ravel_gateway_group'] ?? null,
            'ravel_masquerade' => $params['ravel_masquerade'] ?? null,
            'ravel_settings' => $ravelSettings,
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
        if (!in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess', 'ravel'], true)) {
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

    public function runtimeTask(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'service' => 'required|string|in:ravel,v2node,gost',
            'action' => 'required|string|in:logs,status,start,stop,restart,reload',
            'lines' => 'nullable|integer|min:50|max:300',
        ]);

        $machine = Machine::findOrFail((int) $params['id']);
        $taskCacheKey = 'v2node_probe_runtime_task:' . $machine->id;
        $existingTask = $this->probeCache()->get($taskCacheKey);
        if (is_array($existingTask) && (int) ($existingTask['expires_at'] ?? 0) > time()) {
            abort(409, '该机器已有运行任务正在等待执行');
        }
        $service = strtolower((string) $params['service']);
        if ($service === 'v2node') {
            $service = 'ravel';
        }
        $action = strtolower((string) $params['action']);
        if ($service === 'ravel' && in_array($action, ['start', 'stop'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Ravel 控制进程不允许远程启动或停止',
            ]);
        }

        $now = time();
        $taskId = $now . '-' . Str::random(20);
        $task = [
            'task_id' => $taskId,
            'machine_id' => (int) $machine->id,
            'machine_name' => (string) $machine->name,
            'service' => $service,
            'action' => $action,
            'lines' => max(50, min(300, (int) ($params['lines'] ?? 200))),
            'created_at' => $now,
            'expires_at' => $now + self::RUNTIME_TASK_TTL_SECONDS,
        ];
        $result = array_merge($task, [
            'status' => 'pending',
            'message' => '任务已下发，等待 Ravel 领取',
            'service_status' => '',
            'exit_code' => null,
            'logs' => '',
            'updated_at' => $now,
        ]);

        $this->probeCache()->put(
            $taskCacheKey,
            $task,
            self::RUNTIME_TASK_TTL_SECONDS
        );
        $this->probeCache()->put(
            'v2node_probe_runtime_result:' . $taskId,
            $result,
            self::RUNTIME_TASK_TTL_SECONDS
        );

        return response([
            'data' => $result,
        ]);
    }

    public function runtimeTaskResult(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'task_id' => 'required|string|max:64',
        ]);

        $result = $this->probeCache()->get('v2node_probe_runtime_result:' . $params['task_id']);
        if (!is_array($result) || (int) ($result['machine_id'] ?? 0) !== (int) $params['id']) {
            abort(404, '运行任务不存在或已过期');
        }

        return response([
            'data' => $result,
        ]);
    }

    public function runtimeStreamStart(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'service' => 'required|string|in:ravel,v2node,gost',
        ]);
        $machine = Machine::findOrFail((int) $params['id']);
        $service = new MachineRuntimeStreamService($this->probeCache());

        return response([
            'data' => $service->start($machine, (string) $params['service']),
        ]);
    }

    public function runtimeStreamResult(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'session_id' => 'required|string|max:64',
            'after_sequence' => 'nullable|integer|min:0',
        ]);
        $service = new MachineRuntimeStreamService($this->probeCache());
        $result = $service->result(
            (int) $params['id'],
            (string) $params['session_id'],
            (int) ($params['after_sequence'] ?? 0)
        );
        if (!$result) {
            abort(404, '实时日志会话不存在或已过期');
        }

        return response(['data' => $result]);
    }

    public function runtimeStreamStop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'session_id' => 'required|string|max:64',
        ]);
        $service = new MachineRuntimeStreamService($this->probeCache());
        $result = $service->stop((int) $params['id'], (string) $params['session_id']);
        if (!$result) {
            abort(404, '实时日志会话不存在或已过期');
        }

        return response(['data' => $result]);
    }

    public function updateProbe(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine,id',
            'mode' => 'nullable|string|in:latest,version',
            'version' => 'nullable|string|max:64',
        ]);

        $mode = strtolower(trim((string) ($params['mode'] ?? 'latest')));
        $targetVersion = $mode === 'version'
            ? trim((string) ($params['version'] ?? ''))
            : '';

        if ($mode === 'version' && $targetVersion === '') {
            throw ValidationException::withMessages([
                'version' => '请输入 GitHub Release 版本',
            ]);
        }

        if ($targetVersion !== '' && !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/', $targetVersion)) {
            throw ValidationException::withMessages([
                'version' => '版本只能包含字母、数字、点、下划线、加号和连字符',
            ]);
        }

        $machine = Machine::findOrFail($params['id']);
        $now = time();
        $requestId = $now . '-' . Str::random(16);
        $updateRequest = [
            'request_id' => $requestId,
            'target_version' => $targetVersion,
            'requested_at' => $now,
            'expires_at' => $now + self::PROBE_UPDATE_REQUEST_TTL_SECONDS,
        ];

        MachineUpdateLog::create([
            'machine_id' => (int) $machine->id,
            'request_id' => $requestId,
            'source' => 'manual',
            'target_version' => $targetVersion !== '' ? $targetVersion : 'latest',
            'status' => 'pending',
            'requested_at' => $now,
            'started_at' => 0,
            'completed_at' => 0,
            'expires_at' => $updateRequest['expires_at'],
        ]);

        $this->probeCache()->put(
            'v2node_probe_update:' . $machine->id,
            $updateRequest,
            self::PROBE_UPDATE_REQUEST_TTL_SECONDS
        );

        $status = $this->decodeStatus($machine->status, $machine) ?: [];
        $status['probe_update_action_status'] = 'pending';
        $status['probe_update_action_at'] = $now;
        $status['probe_update_target_version'] = $targetVersion !== '' ? $targetVersion : 'latest';
        $status['probe_update_request_id'] = $requestId;
        $status['probe_update_requested_version'] = $targetVersion;
        $status['probe_update_request_expires_at'] = $updateRequest['expires_at'];
        unset($status['probe_update_action_error']);

        $machine->status = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $machine->save();
        $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);
        Machine::forgetPublicStatusCache();
        $this->forgetAdminFetchCache();

        return response([
            'data' => [
                'request_id' => $requestId,
                'target_version' => $targetVersion,
            ],
            'message' => $targetVersion !== ''
                ? "已下发更新至 {$targetVersion} 的指令"
                : '已下发更新至 GitHub 最新版的指令',
        ]);
    }

    public function probeReleases()
    {
        try {
            $releases = Cache::remember('ravel:github-releases:v1', 600, function () {
                $response = Http::acceptJson()
                    ->withHeaders(['User-Agent' => 'BunCloud-Ravel-Panel'])
                    ->timeout(12)
                    ->get('https://api.github.com/repos/YeJianbo/v2node/releases', [
                        'per_page' => 30,
                    ]);

                if (!$response->successful()) {
                    throw new \RuntimeException('GitHub Release API returned HTTP ' . $response->status());
                }

                return collect($response->json())
                    ->filter(function ($release) {
                        return is_array($release)
                            && empty($release['draft'])
                            && !empty($release['tag_name']);
                    })
                    ->map(function ($release) {
                        return [
                            'tag' => (string) $release['tag_name'],
                            'name' => trim((string) ($release['name'] ?? '')),
                            'published_at' => (string) ($release['published_at'] ?? ''),
                            'prerelease' => (bool) ($release['prerelease'] ?? false),
                        ];
                    })
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            report($e);

            return response([
                'data' => [],
                'message' => 'GitHub Release 列表暂时不可用，请手工输入版本 tag',
            ], 503);
        }

        return response([
            'data' => $releases,
        ]);
    }

    public function probeUpdateHistory(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_machine,id',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $machineId = (int) $params['machine_id'];
        $now = time();
        MachineUpdateLog::query()
            ->where('machine_id', $machineId)
            ->whereIn('status', ['pending', 'running'])
            ->where('expires_at', '>', 0)
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => 'failed',
                'error' => '更新请求超时，机器未在有效期内完成更新',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        $history = MachineUpdateLog::query()
            ->where('machine_id', $machineId)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit((int) ($params['limit'] ?? 20))
            ->get([
                'id',
                'request_id',
                'source',
                'target_version',
                'installed_version',
                'status',
                'error',
                'requested_at',
                'started_at',
                'completed_at',
            ]);

        return response(['data' => $history]);
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
