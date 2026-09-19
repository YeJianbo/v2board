<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineNetworkQuality;
use App\Models\MachineUpdateLog;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Services\SettingService;
use App\Services\MachineMetricService;
use App\Services\MachineRuntimeStreamService;
use App\Services\TelegramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    private const PROBE_AUTO_UPDATE_INTERVAL_SECONDS = 86400;
    private const PROBE_STATUS_PERSIST_INTERVAL_SECONDS = 120;
    private const PROBE_MACHINE_AUTH_CACHE_SECONDS = 600;
    private const DDNS_ERROR_RETRY_INTERVAL_SECONDS = 300;

    private const SIGNATURE_WINDOW_SECONDS = 300;
    private const ENROLL_TOKEN_TTL_SECONDS = 604800;
    private const ENROLL_TOKEN_DEFAULT_MAX_USES = 100;

    private array $panelSettingCache = [];
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

    private function panelSetting(string $key, $default = null)
    {
        if (array_key_exists($key, $this->panelSettingCache)) {
            return $this->panelSettingCache[$key];
        }

        $configValue = config('v2board.' . $key);
        if ($configValue !== null && $configValue !== '') {
            return $this->panelSettingCache[$key] = $configValue;
        }

        $dbValue = app(SettingService::class)->get($key);
        if ($dbValue !== null && $dbValue !== '') {
            return $this->panelSettingCache[$key] = $dbValue;
        }

        return $this->panelSettingCache[$key] = $default;
    }

    private function authenticate(Request $request)
    {
        $signedMachine = $this->authenticateSignedRequest($request);
        if ($signedMachine) {
            return $signedMachine;
        }

        abort(401, 'Unauthorized: Signed probe request required');
    }

    private function restoreMachineFromAttributes(?array $attributes): ?Machine
    {
        if (!$attributes) {
            return null;
        }

        $machine = new Machine();
        $machine->forceFill($attributes);
        $machine->exists = true;

        return $machine;
    }

    private function findMachineForProbeAuth(int $machineId): ?Machine
    {
        $cacheKey = Machine::probeAuthCacheKeyForId($machineId);

        try {
            $attributes = $this->probeCache()->remember($cacheKey, self::PROBE_MACHINE_AUTH_CACHE_SECONDS, function () use ($machineId) {
                $machine = Machine::query()
                    ->select([
                        'id',
                        'name',
                        'host',
                        'api_token',
                        'status',
                        'ddns_enabled',
                        'ddns_provider',
                        'ddns_zone_name',
                        'ddns_record_name',
                        'ddns_record_type',
                        'ddns_ttl',
                        'ddns_proxied',
                        'ddns_api_token',
                        'relay_rules',
                        'probe_auto_update',
                        'maintenance_until',
                        'maintenance_note',
                        'network_quality_enabled',
                        'network_quality_interval',
                        'network_quality_targets',
                        'traffic_alert_enabled',
                        'traffic_alert_bytes',
                        'traffic_alert_window',
                        'traffic_reset_mode',
                        'traffic_reset_day',
                        'traffic_reset_hour',
                        'traffic_reset_minute',
                        'updated_at',
                    ])
                    ->whereKey($machineId)
                    ->first();
                return $machine ? $machine->getAttributes() : null;
            });

            return $this->restoreMachineFromAttributes(is_array($attributes) ? $attributes : null);
        } catch (\Throwable $e) {
            return Machine::find($machineId);
        }
    }

    private function authenticateSignedRequest(Request $request)
    {
        $headerPrefix = 'X-V2Node';
        if (!$request->header('X-V2Node-Machine-Id') && $request->header('X-Ravel-Machine-Id')) {
            $headerPrefix = 'X-Ravel';
        }
        $machineId = $request->header($headerPrefix . '-Machine-Id');
        $timestamp = $request->header($headerPrefix . '-Timestamp');
        $nonce = $request->header($headerPrefix . '-Nonce');
        $signature = $request->header($headerPrefix . '-Signature');

        if (!$machineId && !$timestamp && !$nonce && !$signature) {
            return null;
        }

        if (!$machineId || !$timestamp || !$nonce || !$signature) {
            abort(401, 'Unauthorized: Incomplete signature headers');
        }

        if (!ctype_digit((string) $machineId)) {
            abort(401, 'Unauthorized: Invalid machine id');
        }

        if (!ctype_digit((string) $timestamp)) {
            abort(401, 'Unauthorized: Invalid timestamp');
        }

        $timestamp = (int) $timestamp;
        if (abs(time() - $timestamp) > self::SIGNATURE_WINDOW_SECONDS) {
            abort(401, 'Unauthorized: Signature expired');
        }

        $nonce = trim((string) $nonce);
        if ($nonce === '' || strlen($nonce) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $nonce)) {
            abort(401, 'Unauthorized: Invalid nonce');
        }

        $machine = $this->findMachineForProbeAuth((int) $machineId);
        if (!$machine || empty($machine->api_token)) {
            abort(401, 'Unauthorized: Invalid machine');
        }

        $bodyHash = hash('sha256', (string) $request->getContent());
        $path = '/' . ltrim($request->getPathInfo(), '/');
        $payloadParts = [strtoupper($request->method()), $path];
        if ($headerPrefix === 'X-Ravel') {
            $payloadParts[] = (string) ($request->getQueryString() ?: '');
        }
        array_push($payloadParts, (string) $timestamp, $nonce, $bodyHash);
        $payload = implode("\n", $payloadParts);
        $expected = hash_hmac('sha256', $payload, $machine->api_token);

        if (!hash_equals($expected, strtolower((string) $signature))) {
            abort(401, 'Unauthorized: Invalid signature');
        }

        $nonceCacheKey = 'v2node_probe_nonce:' . $machine->id . ':' . hash('sha256', $nonce);
        if (!$this->probeCache()->add($nonceCacheKey, true, self::SIGNATURE_WINDOW_SECONDS)) {
            abort(401, 'Unauthorized: Replay detected');
        }

        return $machine;
    }

    private function enforceIpWhitelist(Request $request, Machine $machine)
    {
        if (empty($machine->host)) {
            return;
        }

        $allowedIps = array_values(array_filter(
            array_map('trim', explode(',', $machine->host)),
            function ($ip) {
                return filter_var($ip, FILTER_VALIDATE_IP) && !$this->isPrivateIp($ip);
            }
        ));
        if (!$allowedIps) {
            return;
        }

        $candidateIps = array_filter(array_unique(array_merge(
            [$request->ip()],
            $this->extractForwardedIps($request)
        )));

        if (!array_intersect($candidateIps, $allowedIps)) {
            abort(401, 'Unauthorized: IP is not allowed');
        }
    }

    private function extractForwardedIps(Request $request): array
    {
        $ips = [];
        $cfConnectingIp = trim((string) $request->headers->get('CF-Connecting-IP', ''));
        if (filter_var($cfConnectingIp, FILTER_VALIDATE_IP)) {
            $ips[] = $cfConnectingIp;
        }

        $forwardedFor = (string) $request->headers->get('X-Forwarded-For', '');
        foreach (explode(',', $forwardedFor) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private function decodeMachineStatus(Machine $machine): array
    {
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

        if (empty($machine->status)) {
            return [];
        }

        $decoded = json_decode((string) $machine->status, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function isPrivateIp(?string $ip): bool
    {
        $ip = trim((string) $ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function resolveRequestRemoteIp(Request $request): string
    {
        $candidateIps = array_filter(array_unique(array_merge(
            $this->extractForwardedIps($request),
            [$request->ip()]
        )));

        foreach ($candidateIps as $candidateIp) {
            if (!$this->isPrivateIp($candidateIp)) {
                return $candidateIp;
            }
        }

        return (string) ($candidateIps[0] ?? '');
    }

    private function resolvePrimaryIp(?string $reportedIp, ?string $remoteIp): string
    {
        $reportedIp = trim((string) $reportedIp);
        $remoteIp = trim((string) $remoteIp);

        if ($reportedIp !== '' && !$this->isPrivateIp($reportedIp)) {
            return $reportedIp;
        }

        if ($remoteIp !== '' && !$this->isPrivateIp($remoteIp)) {
            return $remoteIp;
        }

        return $reportedIp !== '' ? $reportedIp : $remoteIp;
    }

    private function resolveMachineConnectHost(Machine $machine, ?array $status = null): string
    {
        $status = $status ?? $this->decodeMachineStatus($machine);
        $ddnsHost = $this->resolveDdnsHost($machine);
        $reportedIp = trim((string) ($status['primary_ip'] ?? $status['remote_ip'] ?? $status['ip'] ?? ''));
        $configuredHost = trim((string) $machine->host);

        if ($ddnsHost !== '') {
            return $ddnsHost;
        }

        if ($reportedIp !== '' && !$this->isPrivateIp($reportedIp)) {
            return $reportedIp;
        }

        if ($configuredHost !== '' && !$this->isPrivateIp($configuredHost)) {
            return $configuredHost;
        }

        return $reportedIp !== '' ? $reportedIp : $configuredHost;
    }

    private function touchMachineHeartbeat(Machine $machine, Request $request, array $extraStatus = []): void
    {
        $status = $this->decodeMachineStatus($machine);
        $previousStatus = $status;
        $previousReportedAt = (int) ($status['reported_at'] ?? 0);
        $previousTrafficReportedAt = (int) ($status['traffic_reported_at'] ?? 0);
        $now = time();
        $remoteIp = $this->resolveRequestRemoteIp($request);
        $reportedIp = trim((string) ($extraStatus['ip'] ?? ($status['ip'] ?? '')));
        $primaryIp = $this->resolvePrimaryIp($reportedIp, $remoteIp);
        $previousPrimaryIp = trim((string) ($status['primary_ip'] ?? ''));
        $trafficDeltas = ['net_in' => 0, 'net_out' => 0];
        $hasTrafficSample = false;
        $trafficElapsed = $previousTrafficReportedAt > 0 ? $now - $previousTrafficReportedAt : 0;

        foreach ([
            'net_in' => 'net_in_rate',
            'net_out' => 'net_out_rate',
        ] as $totalKey => $rateKey) {
            if (!array_key_exists($totalKey, $extraStatus) || !is_numeric($extraStatus[$totalKey])) {
                continue;
            }

            $hasTrafficSample = true;
            $currentTotal = (float) $extraStatus[$totalKey];
            $previousTotal = isset($status[$totalKey]) && is_numeric($status[$totalKey])
                ? (float) $status[$totalKey]
                : null;
            $status[$rateKey] = 0;

            if ($previousTotal !== null && $currentTotal >= $previousTotal) {
                $delta = max(0, $currentTotal - $previousTotal);
                $trafficDeltas[$totalKey] = (int) round($delta);
                if ($trafficElapsed > 0 && $trafficElapsed <= 600) {
                    $status[$rateKey] = round($delta / $trafficElapsed, 2);
                }
            }
        }

        if ($hasTrafficSample) {
            $status['traffic_reported_at'] = $now;
        }

        if ($remoteIp !== '') {
            $status['remote_ip'] = $remoteIp;
        }
        if ($primaryIp !== '') {
            $status['primary_ip'] = $primaryIp;
        }
        $status['reported_at'] = $now;

        foreach ($extraStatus as $key => $value) {
            if ($value === null || $value === '') {
                unset($status[$key]);
                continue;
            }

            $status[$key] = $value;
        }

        if ($primaryIp !== '' && (!$this->isPrivateIp($primaryIp))) {
            if ($primaryIp !== $previousPrimaryIp || empty($status['country_code']) || empty($status['country'])) {
                $geo = $this->resolveGeoByIp($primaryIp);
                if (!empty($geo['country_code'])) {
                    $status['country_code'] = $geo['country_code'];
                }
                if (!empty($geo['country'])) {
                    $status['country'] = $geo['country'];
                }
            }
        }

        $status = \App\Services\MachineRuntimeTaskState::separateLegacyQueryError($status);
        $this->recordAutomaticProbeVersionChange($machine, $previousStatus, $status, $now);
        $status = $this->syncMachineDdns($machine, $status);
        $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);
        Machine::forgetPublicStatusCache();

        try {
            app(MachineMetricService::class)->record($machine, $status, $now);
        } catch (\Throwable $e) {
            \Log::warning('主机资源历史采样失败', [
                'machine_id' => (int) $machine->id,
                'message' => $e->getMessage(),
            ]);
        }

        if (($trafficDeltas['net_in'] + $trafficDeltas['net_out']) > 0) {
            try {
                app(TelegramNotificationService::class)->recordMachineTraffic(
                    $machine,
                    $trafficDeltas['net_in'],
                    $trafficDeltas['net_out'],
                    $now
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $forcePersist = $previousReportedAt <= 0
            || ($now - $previousReportedAt) >= self::PROBE_STATUS_PERSIST_INTERVAL_SECONDS
            || $this->hasMachineStatusCriticalChanges($previousStatus, $status);

        if (!$forcePersist) {
            return;
        }

        $encodedStatus = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Machine::query()
            ->whereKey($machine->getKey())
            ->update([
                'status' => $encodedStatus,
                'updated_at' => $now,
            ]);
        $machine->status = $encodedStatus;
        $machine->updated_at = $now;
    }

    private function recordAutomaticProbeVersionChange(
        Machine $machine,
        array $previousStatus,
        array $status,
        int $recordedAt
    ): void {
        $previousVersion = trim((string) ($previousStatus['v2node_version'] ?? ''));
        $currentVersion = trim((string) ($status['v2node_version'] ?? ''));
        if ($previousVersion === '' || $currentVersion === '' || $previousVersion === $currentVersion) {
            return;
        }

        $manualUpdateStatus = strtolower(trim((string) ($previousStatus['probe_update_action_status'] ?? '')));
        if (in_array($manualUpdateStatus, ['pending', 'running'], true)) {
            return;
        }

        MachineUpdateLog::create([
            'machine_id' => (int) $machine->id,
            'request_id' => 'auto-' . $recordedAt . '-' . Str::random(12),
            'source' => $machine->probe_auto_update ? 'auto' : 'detected',
            'target_version' => $currentVersion,
            'installed_version' => $currentVersion,
            'status' => 'success',
            'requested_at' => $recordedAt,
            'started_at' => $recordedAt,
            'completed_at' => $recordedAt,
            'expires_at' => 0,
        ]);
    }

    private function hasMachineStatusCriticalChanges(array $before, array $after): bool
    {
        $criticalKeys = [
            'ip',
            'remote_ip',
            'primary_ip',
            'country',
            'country_code',
            'version',
            'v2node_status',
            'gost_status',
            'gost_version',
			'gost_error',
            'listen_ports',
            'listen_processes',
            'ddns_host',
            'ddns_synced_ip',
            'ddns_synced_at',
            'ddns_error',
            'ddns_fingerprint',
            'primary_ipv4',
            'primary_ipv6',
            'public_ipv4',
            'public_ipv6',
            'tcp_congestion_control',
            'bbr_status',
            'docker_summary',
        ];

        foreach ($criticalKeys as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function resolveGeoByIp(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || $this->isPrivateIp($ip)) {
            return [];
        }

        return $this->probeCache()->remember('machine_geo:' . $ip, 86400, function () use ($ip) {
            try {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get('http://ip-api.com/json/' . $ip, [
                        'fields' => 'status,country,countryCode',
                    ]);

                if (!$response->successful()) {
                    return [];
                }

                $payload = $response->json();
                if (!is_array($payload) || ($payload['status'] ?? '') !== 'success') {
                    return [];
                }

                return [
                    'country_code' => strtoupper((string) ($payload['countryCode'] ?? '')),
                    'country' => (string) ($payload['country'] ?? ''),
                ];
            } catch (\Throwable $e) {
                return [];
            }
        });
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

    private function decryptMachineDdnsApiToken(Machine $machine): string
    {
        $value = trim((string) $machine->ddns_api_token);
        if ($value === '') {
            return '';
        }

        try {
            return (string) Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return preg_match('/^[A-Za-z0-9._-]{20,}$/', $value) ? $value : '';
        }
    }

    private function resolveDdnsCurrentIp(Machine $machine, array $status): string
    {
        $recordType = strtoupper(trim((string) ($machine->ddns_record_type ?: 'A')));
        $candidates = $recordType === 'AAAA'
            ? [
                $status['primary_ipv6'] ?? null,
                $status['public_ipv6'] ?? null,
                $status['ipv6'] ?? null,
                $status['primary_ip'] ?? null,
                $status['remote_ip'] ?? null,
                $status['ip'] ?? null,
            ]
            : [
                $status['primary_ipv4'] ?? null,
                $status['public_ipv4'] ?? null,
                $status['ipv4'] ?? null,
                $status['primary_ip'] ?? null,
                $status['remote_ip'] ?? null,
                $status['ip'] ?? null,
            ];

        $filterFlag = $recordType === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP, $filterFlag)) {
                return $value;
            }
        }

        return '';
    }

    private function buildDdnsSyncFingerprint(Machine $machine, string $host, string $currentIp): string
    {
        return implode('|', [
            strtoupper(trim((string) ($machine->ddns_record_type ?: 'A'))),
            trim((string) ($machine->ddns_provider ?: 'cloudflare')),
            $host,
            $currentIp,
            (int) ($machine->ddns_ttl ?: 120),
            (int) ($machine->ddns_proxied ? 1 : 0),
        ]);
    }

    private function extractCloudflareError($payload, string $fallback): string
    {
        if (!is_array($payload)) {
            return $fallback;
        }

        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
        foreach ($errors as $error) {
            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        foreach ($messages as $messageItem) {
            $message = trim((string) ($messageItem['message'] ?? $messageItem ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    private function syncMachineDdns(Machine $machine, array $status): array
    {
        $ddnsHost = $this->resolveDdnsHost($machine);
        $status['ddns_host'] = $ddnsHost;

        if (!(bool) $machine->ddns_enabled) {
            $status['ddns_synced_ip'] = '';
            $status['ddns_synced_at'] = 0;
            $status['ddns_error'] = '';
            $status['ddns_fingerprint'] = '';
            return $status;
        }

        $provider = trim((string) ($machine->ddns_provider ?: 'cloudflare'));
        $zoneName = trim((string) ($machine->ddns_zone_name ?: ''));
        $recordName = trim((string) ($machine->ddns_record_name ?: ''));
        $recordType = strtoupper(trim((string) ($machine->ddns_record_type ?: 'A')));
        $apiToken = $this->decryptMachineDdnsApiToken($machine);
        $currentIp = $this->resolveDdnsCurrentIp($machine, $status);
        $fingerprint = $this->buildDdnsSyncFingerprint($machine, $ddnsHost, $currentIp);

        if ($provider !== 'cloudflare') {
            $status['ddns_error'] = "暂不支持 {$provider} DDNS";
            return $status;
        }

        if ($zoneName === '' || $recordName === '' || $ddnsHost === '' || $apiToken === '' || $currentIp === '') {
            $status['ddns_error'] = 'DDNS 配置不完整';
            return $status;
        }

        if (
            trim((string) ($status['ddns_synced_ip'] ?? '')) === $currentIp &&
            trim((string) ($status['ddns_fingerprint'] ?? '')) === $fingerprint &&
            trim((string) ($status['ddns_error'] ?? '')) === ''
        ) {
            return $status;
        }

        if (
            trim((string) ($status['ddns_fingerprint'] ?? '')) === $fingerprint &&
            trim((string) ($status['ddns_error'] ?? '')) !== '' &&
            (time() - (int) ($status['ddns_attempted_at'] ?? 0)) < self::DDNS_ERROR_RETRY_INTERVAL_SECONDS
        ) {
            return $status;
        }

        $status['ddns_attempted_at'] = time();

        try {
            $client = Http::baseUrl('https://api.cloudflare.com/client/v4')
                ->timeout(8)
                ->acceptJson()
                ->withToken($apiToken);

            $zoneResponse = $client->get('/zones', ['name' => $zoneName]);
            $zonePayload = $zoneResponse->json();
            if (!$zoneResponse->successful() || !is_array($zonePayload) || !($zonePayload['success'] ?? false)) {
                $status['ddns_error'] = '获取 Cloudflare Zone 失败：' . $this->extractCloudflareError($zonePayload, 'unknown');
                return $status;
            }

            $zoneId = trim((string) ($zonePayload['result'][0]['id'] ?? ''));
            if ($zoneId === '') {
                $status['ddns_error'] = '未找到 Cloudflare Zone';
                return $status;
            }

            $recordResponse = $client->get("/zones/{$zoneId}/dns_records", [
                'type' => $recordType,
                'name' => $ddnsHost,
            ]);
            $recordPayload = $recordResponse->json();
            if (!$recordResponse->successful() || !is_array($recordPayload) || !($recordPayload['success'] ?? false)) {
                $status['ddns_error'] = '获取 Cloudflare 记录失败：' . $this->extractCloudflareError($recordPayload, 'unknown');
                return $status;
            }

            $requestBody = [
                'type' => $recordType,
                'name' => $ddnsHost,
                'content' => $currentIp,
                'ttl' => (int) ($machine->ddns_ttl ?: 120),
                'proxied' => (bool) $machine->ddns_proxied,
            ];
            $recordId = trim((string) ($recordPayload['result'][0]['id'] ?? ''));
            $writeResponse = $recordId !== ''
                ? $client->put("/zones/{$zoneId}/dns_records/{$recordId}", $requestBody)
                : $client->post("/zones/{$zoneId}/dns_records", $requestBody);
            $writePayload = $writeResponse->json();
            if (!$writeResponse->successful() || !is_array($writePayload) || !($writePayload['success'] ?? false)) {
                $status['ddns_error'] = '更新 Cloudflare 记录失败：' . $this->extractCloudflareError($writePayload, 'unknown');
                return $status;
            }

            $status['ddns_synced_ip'] = $currentIp;
            $status['ddns_synced_at'] = time();
            $status['ddns_error'] = '';
            $status['ddns_fingerprint'] = $fingerprint;
            return $status;
        } catch (\Throwable $e) {
            $status['ddns_error'] = '更新 Cloudflare 记录失败：' . $e->getMessage();
            return $status;
        }
    }

    private function resolveV2nodeFirewallProtocols(ServerV2node $server): array
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

    private function buildProbeRelayRules(Machine $machine, ?array $manualRelayRules = null): array
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
        $manualRelayRules = $manualRelayRules ?? $this->buildMachineRelayRules($machine);
        $manualOverrideMap = $this->buildRelayRuleOverrideMap($manualRelayRules);
        $status = $this->decodeMachineStatus($machine);
        $listenProcessMap = $this->buildListenProcessMap($status);
        $occupiedAutoMap = [];

        return $servers->map(function (ServerV2node $server) use ($parentServers, $manualOverrideMap, $listenProcessMap, &$occupiedAutoMap) {
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
                $this->resolveV2nodeFirewallProtocols($server),
                $manualOverrideMap
            );
            if (!$protocols) {
                return null;
            }

            $listenKey = strtolower($listenHost) . ':' . $listenPort;
            $protocols = array_values(array_filter($protocols, function ($protocol) use ($listenKey, $listenPort, $listenProcessMap, &$occupiedAutoMap) {
                $protocol = strtolower(trim((string) $protocol));
                if (
                    $protocol === '' ||
                    isset($occupiedAutoMap[$listenKey][$protocol]) ||
                    $this->isPortHeldByExternalProcess($listenProcessMap, $protocol, $listenPort)
                ) {
                    return false;
                }
                $occupiedAutoMap[$listenKey][$protocol] = true;
                return true;
            }));
            if (!$protocols) {
                return null;
            }

            return [
                'node_id' => (int) $server->id,
                'name' => (string) $server->name,
                'protocol' => strtolower(trim((string) $server->protocol)),
                'listen_host' => $listenHost,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'protocols' => $protocols,
            ];
        })->filter()->values()->all();
    }

    private function filterDeployableMachineV2nodeServers($servers, array $status = [])
    {
        $occupied = [];
        $listenProcessMap = $this->buildListenProcessMap($status);

        return $servers->filter(function (ServerV2node $server) use (&$occupied, $listenProcessMap) {
            $port = (int) $server->server_port;
            if ($port < 1 || $port > 65535) {
                return false;
            }

            $protocols = $this->resolveV2nodeFirewallProtocols($server);
            if (!$protocols) {
                return false;
            }

            $availableProtocols = [];
            foreach ($protocols as $protocol) {
                $protocol = strtolower(trim((string) $protocol));
                if (!in_array($protocol, ['tcp', 'udp'], true)) {
                    continue;
                }
                if (isset($occupied[$port][$protocol])) {
                    continue;
                }
                if ($this->isPortHeldByExternalProcess($listenProcessMap, $protocol, $port)) {
                    continue;
                }
                $availableProtocols[] = $protocol;
            }

            if (!$availableProtocols) {
                return false;
            }

            foreach ($availableProtocols as $protocol) {
                $occupied[$port][$protocol] = true;
            }

            return true;
        })->values();
    }

    private function filterDeployableRelayRules(array $rules, array $status = []): array
    {
        $listenProcessMap = $this->buildListenProcessMap($status);

        return array_values(array_filter(array_map(function ($rule) use ($listenProcessMap) {
            if (!is_array($rule)) {
                return null;
            }

            $listenPort = (int) ($rule['listen_port'] ?? $rule['listenPort'] ?? 0);
            if ($listenPort < 1 || $listenPort > 65535) {
                return null;
            }

            $protocols = $this->normalizeRelayRuleProtocols($rule);
            $protocols = array_values(array_filter($protocols, function ($protocol) use ($listenProcessMap, $listenPort) {
                $protocol = strtolower(trim((string) $protocol));
                return in_array($protocol, ['tcp', 'udp'], true)
                    && !$this->isPortHeldByExternalProcess($listenProcessMap, $protocol, $listenPort);
            }));
            if (!$protocols) {
                return null;
            }

            $rule['protocols'] = $protocols;
            return $rule;
        }, $rules)));
    }

    private function buildListenProcessMap(array $status): array
    {
        $raw = trim((string) ($status['listen_processes'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $item) {
            $parts = explode(':', trim((string) $item), 4);
            if (count($parts) < 3) {
                continue;
            }

            $protocol = strtolower(trim((string) $parts[0]));
            $port = (int) ($parts[1] ?? 0);
            $process = strtolower(trim((string) ($parts[2] ?? '')));
            $pid = trim((string) ($parts[3] ?? ''));
            if (!in_array($protocol, ['tcp', 'udp'], true) || $port < 1 || $port > 65535) {
                continue;
            }

            $map[$protocol . ':' . $port][] = [
                'process' => $process !== '' ? $process : 'unknown',
                'pid' => $pid,
            ];
        }

        return $map;
    }

    private function isManagedListenProcess(string $process): bool
    {
        $process = strtolower(trim($process));
        return $process === 'v2node' || $process === 'gost' || str_starts_with($process, 'gost-');
    }

    private function isPortHeldByExternalProcess(array $listenProcessMap, string $protocol, int $port): bool
    {
        $items = $listenProcessMap[strtolower($protocol) . ':' . $port] ?? [];
        foreach ($items as $item) {
            if (!$this->isManagedListenProcess((string) ($item['process'] ?? ''))) {
                return true;
            }
        }

        return false;
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

    private function buildMachineRelayRules(Machine $machine): array
    {
        $relayRules = is_array($machine->relay_rules) ? $machine->relay_rules : [];

        return collect($relayRules)
            ->map(function ($rule, $index) {
                if (!is_array($rule)) {
                    return null;
                }

                $listenHost = trim((string) ($rule['listen_host'] ?? $rule['listenHost'] ?? $rule['local_host'] ?? $rule['localHost'] ?? '0.0.0.0'));
                $targetHost = trim((string) ($rule['target_host'] ?? $rule['targetHost'] ?? $rule['remote_host'] ?? $rule['remoteHost'] ?? $rule['host'] ?? ''));
                $listenPort = (int) ($rule['listen_port'] ?? $rule['listenPort'] ?? $rule['local_port'] ?? $rule['localPort'] ?? 0);
                $targetPort = (int) ($rule['target_port'] ?? $rule['targetPort'] ?? $rule['remote_port'] ?? $rule['remotePort'] ?? $rule['port'] ?? 0);
                $protocols = $this->normalizeRelayRuleProtocols($rule);

                if ($targetHost === '' || $listenPort < 1 || $listenPort > 65535 || $targetPort < 1 || $targetPort > 65535 || !$protocols) {
                    return null;
                }

                return [
                    'name' => trim((string) ($rule['remark'] ?? '')) ?: ('relay-' . ($index + 1)),
                    'listen_host' => $listenHost !== '' ? $listenHost : '0.0.0.0',
                    'listen_port' => $listenPort,
                    'target_host' => $targetHost,
                    'target_port' => $targetPort,
                    'protocols' => $protocols,
                ];
            })
            ->filter()
            ->values()
            ->all();
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

    private function buildProbeFirewallRules(Machine $machine, $servers = null): array
    {
        $servers = $servers ?: ServerV2node::query()
            ->where('machine_id', $machine->id)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $servers->map(function (ServerV2node $server) {
                $port = (int) $server->server_port;
                if ($port < 1 || $port > 65535) {
                    return null;
                }

                return [
                    'node_id' => (int) $server->id,
                    'name' => (string) $server->name,
                    'port' => $port,
                    'protocol' => strtolower(trim((string) $server->protocol)),
                    'protocols' => $this->resolveV2nodeFirewallProtocols($server),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function config(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $nodes = [];

        // In local environment without a proper app_url, we just default to local
        $apiHost = $this->panelSetting('server_api_url')
            ?: $request->getSchemeAndHttpHost()
            ?: $this->panelSetting('app_url')
            ?: config('app.url', 'http://127.0.0.1:8000');
        // Force the same host that requested if config is broken
        if (empty($apiHost) || strpos($apiHost, 'localhost') !== false) {
            $apiHost = $request->getSchemeAndHttpHost();
        }
        $apiKey = (string) $this->panelSetting('server_token', '');

        $types = [
            'vmess' => ServerVmess::class,
            'trojan' => ServerTrojan::class,
            'shadowsocks' => ServerShadowsocks::class,
            'vless' => ServerVless::class,
            'hysteria' => ServerHysteria::class,
            'tuic' => ServerTuic::class,
        ];

        foreach ($types as $type => $modelClass) {
            if (!class_exists($modelClass)) continue;
            
            $servers = $modelClass::where('machine_id', $machine->id)->get();
            foreach ($servers as $server) {
                $core = 'xray';
                if ($type === 'hysteria') {
                    $core = 'hysteria2';
                } else if ($type === 'tuic') {
                    $core = 'sing';
                }
                
                $nodes[] = [
                    'ApiConfig' => [
                        'ApiHost' => $apiHost,
                        'NodeID' => $server->id,
                        'ApiKey' => $apiKey,
                        'NodeType' => $type,
                        'Timeout' => 30
                    ],
                    'Options' => [
                        'Name' => $server->name,
                        'Core' => $core,
                        'ListenIP' => '0.0.0.0',
                        'SendIP' => '0.0.0.0'
                    ]
                ];
            }
        }

        return response()->json([
            'data' => $nodes
        ]);
    }

    public function push(Request $request)
    {
        $machine = $this->authenticate($request);
        
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $request->all();
        }
        
        $remoteIp = $this->resolveRequestRemoteIp($request);
        $reportedIp = trim((string) ($data['ip'] ?? ''));
        $stringStatusKeys = [
            'os',
            'kernel',
            'arch',
            'cpu_model',
            'v2node_status',
            'gost_status',
            'gost_version',
            'gost_error',
            'listen_ports',
            'listen_processes',
            'virtualization',
            'tcp_congestion_control',
            'bbr_status',
            'docker_summary',
            'ipv4',
            'ipv6',
            'public_ipv4',
            'public_ipv6',
            'primary_ipv4',
            'primary_ipv6',
            'v2node_version',
            'config_desired_revision',
            'config_applied_revision',
            'config_apply_status',
            'config_apply_error',
            'runtime_health',
            'runtime_error',
            'network_rate_interfaces',
        ];
        $numericStatusKeys = [
            'load1',
            'load5',
            'load15',
            'cpu_cores',
            'process_count',
            'mem_total',
            'mem_used',
            'swap_total',
            'swap_used',
            'swap_percent',
            'disk_total',
            'disk_used',
            'tcp_conn',
            'tcp_established',
            'udp_conn',
            'docker_total',
            'docker_running',
            'docker_images',
            'gost_rule_count',
            'config_apply_at',
        ];

        $status = [
            'cpu' => $data['cpu'] ?? 0,
            'mem' => $data['mem'] ?? 0,
            'disk' => $data['disk'] ?? 0,
            'net_out' => $data['net_tx'] ?? 0,
            'net_in' => $data['net_rx'] ?? 0,
            'uptime' => $data['uptime'] ?? 0,
            'ip' => $reportedIp !== '' ? $reportedIp : $remoteIp,
            'remote_ip' => $remoteIp,
            'primary_ip' => $this->resolvePrimaryIp($reportedIp, $remoteIp),
            'reported_at' => time(),
            'version' => $data['version'] ?? 'unknown',
            'ddns_host' => trim((string) ($data['ddns_host'] ?? '')),
            'ddns_synced_ip' => trim((string) ($data['ddns_synced_ip'] ?? '')),
            'ddns_synced_at' => !empty($data['ddns_synced_at']) ? (int) $data['ddns_synced_at'] : null,
            'ddns_error' => array_key_exists('ddns_error', $data) ? trim((string) $data['ddns_error']) : null,
        ];

        if (array_key_exists('net_rx_rate', $data) && is_numeric($data['net_rx_rate'])) {
            $status['net_in_rate'] = max(0, (float) $data['net_rx_rate']);
        }
        if (array_key_exists('net_tx_rate', $data) && is_numeric($data['net_tx_rate'])) {
            $status['net_out_rate'] = max(0, (float) $data['net_tx_rate']);
        }
        if (array_key_exists('net_rx_peak_rate', $data) && is_numeric($data['net_rx_peak_rate'])) {
            $status['net_in_sample_peak'] = max(0, (float) $data['net_rx_peak_rate']);
        }
        if (array_key_exists('net_tx_peak_rate', $data) && is_numeric($data['net_tx_peak_rate'])) {
            $status['net_out_sample_peak'] = max(0, (float) $data['net_tx_peak_rate']);
        }

        // A push is a fresh address snapshot. Heartbeat-only requests keep the snapshot.
        // Older reporters omit these aliases; retaining them mixes old and current IPs.
        foreach (['ipv4', 'ipv6', 'public_ipv4', 'public_ipv6', 'primary_ipv4', 'primary_ipv6'] as $key) {
            $status[$key] = null;
        }

        foreach ($numericStatusKeys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                $status[$key] = $data[$key] + 0;
            }
        }

        foreach ($stringStatusKeys as $key) {
            if (array_key_exists($key, $data)) {
                $status[$key] = mb_substr(trim((string) $data[$key]), 0, 255);
            }
        }

        if (isset($data['node_health']) && is_array($data['node_health'])) {
            $status['node_health'] = $this->normalizeNodeHealth($data['node_health']);
        }
        if (isset($data['gost_rule_health']) && is_array($data['gost_rule_health'])) {
            $status['gost_rule_health'] = $this->normalizeGostRuleHealth($data['gost_rule_health']);
        }
        
        $runtimeStreamService = new MachineRuntimeStreamService($this->probeCache());
        if (isset($data['runtime_stream']) && is_array($data['runtime_stream'])) {
            $runtimeStreamService->append((int) $machine->id, [
                'session_id' => mb_substr(trim((string) ($data['runtime_stream']['session_id'] ?? '')), 0, 64),
                'logs' => (string) ($data['runtime_stream']['logs'] ?? ''),
                'error' => mb_substr(trim((string) ($data['runtime_stream']['error'] ?? '')), 0, 255),
                'collected_at' => (int) ($data['runtime_stream']['collected_at'] ?? time()),
            ]);
        }

        $this->touchMachineHeartbeat($machine, $request, $status);

        return response()->json([
            'data' => 'success',
            'runtime_stream' => $runtimeStreamService->activeForMachine((int) $machine->id),
        ]);
    }

    public function enroll(Request $request)
    {
        $params = $request->validate([
            'enroll_token' => 'required|string',
            'name' => 'nullable|string|max:100',
            'host' => 'nullable|string|max:255',
            'machine_id' => 'nullable|integer|exists:v2_machine,id',
        ]);

        $cacheKey = 'v2node_probe_enroll:' . hash('sha256', (string) $params['enroll_token']);
        $enrollMeta = $this->probeCache()->get($cacheKey);
        if (!$enrollMeta) {
            abort(401, 'Unauthorized: Invalid enrollment token');
        }

        if (!is_array($enrollMeta)) {
            $enrollMeta = [
                'uses' => 0,
                'max_uses' => self::ENROLL_TOKEN_DEFAULT_MAX_USES,
                'created_at' => time(),
                'expires_at' => time() + self::ENROLL_TOKEN_TTL_SECONDS,
            ];
        }

        $uses = max(0, (int) ($enrollMeta['uses'] ?? 0));
        $maxUses = max(1, (int) ($enrollMeta['max_uses'] ?? self::ENROLL_TOKEN_DEFAULT_MAX_USES));
        $expiresAt = (int) ($enrollMeta['expires_at'] ?? (time() + self::ENROLL_TOKEN_TTL_SECONDS));
        if ($expiresAt <= time()) {
            $this->probeCache()->forget($cacheKey);
            abort(401, 'Unauthorized: Enrollment token expired');
        }

        if ($uses >= $maxUses) {
            $this->probeCache()->forget($cacheKey);
            abort(401, 'Unauthorized: Enrollment token usage limit exceeded');
        }

        $host = trim((string) ($params['host'] ?? ''));
        if ($host === '') {
            $host = $request->ip();
        }

        if (!empty($params['machine_id'])) {
            $machine = Machine::findOrFail((int) $params['machine_id']);
            if (empty($machine->api_token)) {
                $machine->api_token = Str::random(32);
                $machine->save();
            }
        } else {
            $name = trim((string) ($params['name'] ?? ''));
            if ($name === '') {
                $name = 'Probe ' . $host;
            }

            $machine = Machine::create([
                'name' => $name,
                'host' => $host,
                'api_token' => Str::random(32),
            ]);
        }

        $uses++;
        if ($uses >= $maxUses) {
            $this->probeCache()->forget($cacheKey);
        } else {
            $enrollMeta['uses'] = $uses;
            $enrollMeta['max_uses'] = $maxUses;
            $enrollMeta['expires_at'] = $expiresAt;
            $this->probeCache()->put($cacheKey, $enrollMeta, max(1, $expiresAt - time()));
        }

        return response()->json([
            'data' => [
                'machine_id' => (int) $machine->id,
                'api_token' => (string) $machine->api_token,
                'name' => (string) $machine->name,
                'host' => (string) $machine->host,
            ],
        ]);
    }

    public function v2nodeConfig(Request $request)
    {
        $machine = $this->authenticate($request);
        $machine = Machine::findOrFail((int) $machine->id);
        $this->touchMachineHeartbeat($machine, $request);
        $status = $this->decodeMachineStatus($machine);

        $apiHost = rtrim((string) (
            $this->panelSetting('server_api_url')
                ?: $request->getSchemeAndHttpHost()
                ?: $this->panelSetting('app_url')
                ?: config('app.url', '')
        ), '/');
        if (empty($apiHost) || strpos($apiHost, 'localhost') !== false) {
            $apiHost = rtrim($request->getSchemeAndHttpHost(), '/');
        }

        $apiKey = (string) $this->panelSetting('server_token', '');

        $machineServers = ServerV2node::query()
            ->where('machine_id', $machine->id)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        $machineServers = $this->filterDeployableMachineV2nodeServers($machineServers, $status);

        $nodes = $machineServers
            ->map(function (ServerV2node $server) use ($apiHost, $apiKey) {
                return [
                    'ApiHost' => $apiHost,
                    'NodeID' => (int) $server->id,
                    'ApiKey' => $apiKey,
                    'Timeout' => 15,
                ];
            })
            ->values();

        $restartToken = $this->probeCache()->get('v2node_probe_restart:' . $machine->id);
        $enableBbrToken = $this->probeCache()->get('v2node_probe_enable_bbr:' . $machine->id);
        $updateRequestKey = 'v2node_probe_update:' . $machine->id;
        $updateRequest = $this->probeCache()->get($updateRequestKey);
        $updateStatusChanged = false;
        if (!is_array($updateRequest) || (int) ($updateRequest['expires_at'] ?? 0) <= time()) {
            if ($updateRequest) {
                $this->probeCache()->forget($updateRequestKey);
            }
            $persistedRequestId = trim((string) ($status['probe_update_request_id'] ?? ''));
            $persistedExpiresAt = (int) ($status['probe_update_request_expires_at'] ?? 0);
            $persistedActionStatus = strtolower(trim((string) ($status['probe_update_action_status'] ?? '')));
            if ($persistedRequestId !== ''
                && $persistedExpiresAt > time()
                && in_array($persistedActionStatus, ['pending', 'running'], true)
            ) {
                $updateRequest = [
                    'request_id' => $persistedRequestId,
                    'target_version' => trim((string) ($status['probe_update_requested_version'] ?? '')),
                    'requested_at' => (int) ($status['probe_update_action_at'] ?? time()),
                    'expires_at' => $persistedExpiresAt,
                ];
                $this->probeCache()->put(
                    $updateRequestKey,
                    $updateRequest,
                    max(1, $persistedExpiresAt - time())
                );
            } else {
                $updateRequest = null;
                if ($persistedRequestId !== ''
                    && $persistedExpiresAt > 0
                    && $persistedExpiresAt <= time()
                    && in_array($persistedActionStatus, ['pending', 'running'], true)
                ) {
                    $status['probe_update_action_status'] = 'failed';
                    $status['probe_update_action_at'] = time();
                    $status['probe_update_action_error'] = '更新请求超时，机器未在有效期内完成更新';
                    MachineUpdateLog::query()
                        ->where('machine_id', $machine->id)
                        ->where('request_id', $persistedRequestId)
                        ->whereIn('status', ['pending', 'running'])
                        ->update([
                            'status' => 'failed',
                            'error' => '更新请求超时，机器未在有效期内完成更新',
                            'completed_at' => time(),
                            'updated_at' => time(),
                        ]);
                    unset(
                        $status['probe_update_request_id'],
                        $status['probe_update_requested_version'],
                        $status['probe_update_request_expires_at']
                    );
                    $updateStatusChanged = true;
                }
            }
        }
        if (is_array($updateRequest)
            && strtolower(trim((string) ($status['probe_update_action_status'] ?? ''))) === 'pending'
        ) {
            $status['probe_update_action_status'] = 'running';
            $status['probe_update_action_at'] = time();
            MachineUpdateLog::query()
                ->where('machine_id', $machine->id)
                ->where('request_id', (string) ($updateRequest['request_id'] ?? ''))
                ->where('status', 'pending')
                ->update([
                    'status' => 'running',
                    'started_at' => time(),
                    'updated_at' => time(),
                ]);
            $updateStatusChanged = true;
        }
        if ($updateStatusChanged) {
            $machine->status = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $machine->save();
            $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);
            Machine::forgetPublicStatusCache();
        }
        $ddnsHost = $this->resolveDdnsHost($machine);
        $currentIp = trim((string) ($status['primary_ip'] ?? $status['remote_ip'] ?? $status['ip'] ?? ''));
        $manualRelayRules = $this->filterDeployableRelayRules($this->buildMachineRelayRules($machine), $status);
        $relayRules = array_values(array_merge(
            $manualRelayRules,
            $this->buildProbeRelayRules($machine, $manualRelayRules)
        ));
        $nodePayload = $nodes->all();
        $configRevision = hash('sha256', json_encode([
            'nodes' => $nodePayload,
            'relay' => ['rules' => $relayRules],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'data' => $nodePayload,
            'config_schema' => 1,
            'config_revision' => $configRevision,
            'authoritative' => true,
            'restart_v2node_token' => $restartToken ?: '',
            'enable_bbr_token' => $enableBbrToken ?: '',
            'probe' => [
                'firewall_rules' => $this->buildProbeFirewallRules($machine, $machineServers),
                'relay' => [
                    'rules' => $relayRules,
                ],
                'ddns' => [
                    'enabled' => false,
                    'provider' => (string) ($machine->ddns_provider ?: 'cloudflare'),
                    'zone_name' => (string) ($machine->ddns_zone_name ?: ''),
                    'record_name' => (string) ($machine->ddns_record_name ?: ''),
                    'host' => $ddnsHost,
                    'record_type' => strtoupper((string) ($machine->ddns_record_type ?: 'A')),
                    'ttl' => (int) ($machine->ddns_ttl ?: 120),
                    'proxied' => (bool) $machine->ddns_proxied,
                    'api_token' => '',
                    'current_ip' => '',
                    'last_synced_ip' => trim((string) ($status['ddns_synced_ip'] ?? '')),
                    'last_synced_at' => !empty($status['ddns_synced_at']) ? (int) $status['ddns_synced_at'] : 0,
                ],
                'auto_update' => [
                    // Let an older Ravel bootstrap to the release that understands manual requests.
                    'enabled' => (bool) $machine->probe_auto_update || is_array($updateRequest),
                    'interval_seconds' => self::PROBE_AUTO_UPDATE_INTERVAL_SECONDS,
                    'repo' => 'YeJianbo/v2node',
                    'request_id' => (string) ($updateRequest['request_id'] ?? ''),
                    'target_version' => (string) ($updateRequest['target_version'] ?? ''),
                    'requested_at' => (int) ($updateRequest['requested_at'] ?? 0),
                ],
                'network_quality' => [
                    'enabled' => (bool) $machine->network_quality_enabled,
                    'interval_seconds' => max(60, min(3600, (int) ($machine->network_quality_interval ?: 300))),
                    'packet_count' => 5,
                    'timeout_seconds' => 2,
                    'targets' => $machine->resolvedNetworkQualityTargets(),
                ],
                'connectivity_test_task' => $this->buildProbeConnectivityTask($machine),
                'runtime_task' => $this->buildProbeRuntimeTask($machine),
            ],
        ]);
    }

    public function unregister(Request $request)
    {
        $machine = $this->authenticate($request);
        $machine = Machine::findOrFail((int) $machine->id);
        $machine->delete();

        return response()->json([
            'data' => 'success',
        ]);
    }

    public function networkQuality(Request $request)
    {
        $machine = $this->authenticate($request);
        $machine = Machine::findOrFail((int) $machine->id);
        if (!$machine->network_quality_enabled) {
            return response()->json(['data' => ['accepted' => false]]);
        }

        $targets = collect($machine->resolvedNetworkQualityTargets())
            ->filter(fn ($target) => ($target['enabled'] ?? true) === true)
            ->keyBy('key');
        $params = $request->validate([
            'samples' => 'required|array|min:1|max:8',
            'samples.*.key' => 'required|string|max:32',
            'samples.*.sent' => 'required|integer|min:1|max:20',
            'samples.*.received' => 'required|integer|min:0|max:20',
            'samples.*.packet_loss' => 'required|numeric|min:0|max:100',
            'samples.*.latency_min' => 'nullable|numeric|min:0|max:60000',
            'samples.*.latency_avg' => 'nullable|numeric|min:0|max:60000',
            'samples.*.latency_max' => 'nullable|numeric|min:0|max:60000',
        ]);

        if (count($params['samples']) !== $targets->count()) {
            abort(422, '网络质量目标数量与面板配置不一致');
        }

        $now = time();
        $seen = [];
        $rows = [];
        foreach ($params['samples'] as $sample) {
            $key = (string) $sample['key'];
            if (isset($seen[$key])) {
                abort(422, '网络质量数据包含重复目标');
            }
            $seen[$key] = true;
            $target = $targets->get($key);
            if (!$target) {
                abort(422, '网络质量数据包含未知目标');
            }
            $sent = (int) $sample['sent'];
            $received = min($sent, (int) $sample['received']);
            $rows[] = [
                'machine_id' => (int) $machine->id,
                'target_key' => $key,
                'target_name' => $target['name'],
                'target_host' => $target['host'],
                'probe_type' => $target['probe_type'] ?? 'icmp',
                'target_port' => (int) ($target['port'] ?? 80),
                'ip_version' => $target['ip_version'] ?? 'auto',
                'sent' => $sent,
                'received' => $received,
                'packet_loss' => round((float) $sample['packet_loss'], 2),
                'latency_min' => $received > 0 ? ($sample['latency_min'] ?? null) : null,
                'latency_avg' => $received > 0 ? ($sample['latency_avg'] ?? null) : null,
                'latency_max' => $received > 0 ? ($sample['latency_max'] ?? null) : null,
                'recorded_at' => $now,
                'created_at' => $now,
            ];
        }

        MachineNetworkQuality::query()->insert($rows);
        try {
            app(TelegramNotificationService::class)->handleNetworkQualitySamples($machine, $rows);
        } catch (\Throwable $e) {
            \Log::warning('Telegram网络质量告警处理失败', [
                'machine_id' => (int) $machine->id,
                'message' => $e->getMessage(),
            ]);
        }
        if ($this->probeCache()->add('machine_network_quality_prune', true, 86400)) {
            MachineNetworkQuality::query()->where('recorded_at', '<', now()->subDays(31)->timestamp)->delete();
        }

        return response()->json(['data' => ['accepted' => true, 'recorded_at' => $now]]);
    }

    public function restartAck(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $params = $request->validate([
            'restart_token' => 'required|string',
        ]);

        $cacheKey = 'v2node_probe_restart:' . $machine->id;
        if (hash_equals((string) $this->probeCache()->get($cacheKey, ''), (string) $params['restart_token'])) {
            $this->probeCache()->forget($cacheKey);
        }

        return response()->json([
            'data' => 'success',
        ]);
    }

    public function bbrAck(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $params = $request->validate([
            'enable_bbr_token' => 'required|string',
            'status' => 'nullable|string|max:32',
            'error' => 'nullable|string|max:255',
        ]);

        $cacheKey = 'v2node_probe_enable_bbr:' . $machine->id;
        if (hash_equals((string) $this->probeCache()->get($cacheKey, ''), (string) $params['enable_bbr_token'])) {
            $this->probeCache()->forget($cacheKey);
        }

        $status = $this->decodeMachineStatus($machine);
        $actionStatus = strtolower(trim((string) ($params['status'] ?? 'success')));
        if (!in_array($actionStatus, ['success', 'failed'], true)) {
            $actionStatus = 'success';
        }

        $status['bbr_action_status'] = $actionStatus;
        $status['bbr_action_at'] = time();
        $actionError = trim((string) ($params['error'] ?? ''));
        if ($actionStatus === 'failed' && $actionError !== '') {
            $status['bbr_action_error'] = mb_substr($actionError, 0, 255);
        } else {
            unset($status['bbr_action_error']);
        }

        $encodedStatus = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $machine->status = $encodedStatus;
        $machine->save();
        $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);
        Machine::forgetPublicStatusCache();

        return response()->json([
            'data' => 'success',
        ]);
    }

    public function updateAck(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $params = $request->validate([
            'request_id' => 'required|string|max:64',
            'status' => 'required|string|in:success,failed',
            'target_version' => 'nullable|string|max:64',
            'installed_version' => 'nullable|string|max:64',
            'error' => 'nullable|string|max:255',
        ]);

        $cacheKey = 'v2node_probe_update:' . $machine->id;
        $updateRequest = $this->probeCache()->get($cacheKey);
        $status = $this->decodeMachineStatus($machine);
        if (!is_array($updateRequest) || !hash_equals(
            (string) ($updateRequest['request_id'] ?? ''),
            (string) $params['request_id']
        )) {
            $persistedRequestId = (string) ($status['probe_update_request_id'] ?? '');
            if ($persistedRequestId === '' || !hash_equals($persistedRequestId, (string) $params['request_id'])) {
                abort(404, 'Update request not found');
            }
            $updateRequest = [
                'request_id' => $persistedRequestId,
                'target_version' => trim((string) ($status['probe_update_requested_version'] ?? '')),
            ];
        }

        $this->probeCache()->forget($cacheKey);

        $actionStatus = (string) $params['status'];
        $targetVersion = trim((string) ($updateRequest['target_version'] ?? $params['target_version'] ?? ''));
        $installedVersion = trim((string) ($params['installed_version'] ?? ''));
        $actionError = trim((string) ($params['error'] ?? ''));

        $status['probe_update_action_status'] = $actionStatus;
        $status['probe_update_action_at'] = time();
        $status['probe_update_target_version'] = $targetVersion !== '' ? $targetVersion : 'latest';
        $status['probe_update_last_request_id'] = (string) $params['request_id'];
        unset(
            $status['probe_update_request_id'],
            $status['probe_update_requested_version'],
            $status['probe_update_request_expires_at']
        );
        if ($installedVersion !== '') {
            $status['probe_update_installed_version'] = $installedVersion;
            if ($actionStatus === 'success') {
                $status['v2node_version'] = $installedVersion;
            }
        }
        if ($actionStatus === 'failed' && $actionError !== '') {
            $status['probe_update_action_error'] = mb_substr($actionError, 0, 255);
        } else {
            unset($status['probe_update_action_error']);
        }

        $updateLog = MachineUpdateLog::query()
            ->where('machine_id', $machine->id)
            ->where('request_id', (string) $params['request_id'])
            ->first();
        if (!$updateLog) {
            $updateLog = new MachineUpdateLog([
                'machine_id' => (int) $machine->id,
                'request_id' => (string) $params['request_id'],
                'source' => 'unknown',
                'requested_at' => (int) ($status['probe_update_action_at'] ?? time()),
                'started_at' => 0,
                'expires_at' => 0,
            ]);
        }
        $updateLog->target_version = $targetVersion !== '' ? $targetVersion : 'latest';
        $updateLog->installed_version = $installedVersion !== '' ? $installedVersion : null;
        $updateLog->status = $actionStatus;
        $updateLog->error = $actionStatus === 'failed' && $actionError !== ''
            ? mb_substr($actionError, 0, 255)
            : null;
        $updateLog->completed_at = time();
        $updateLog->save();

        $machine->status = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $machine->save();
        $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);
        Machine::forgetPublicStatusCache();

        return response()->json([
            'data' => 'success',
        ]);
    }

    public function connectivityTestAck(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $params = $request->validate([
            'task_id' => 'required|string|max:64',
            'status' => 'required|string|in:success,failed',
            'message' => 'nullable|string|max:255',
            'latency_ms' => 'nullable|integer|min:0|max:600000',
            'http_code' => 'nullable|integer|min:0|max:999',
            'logs' => 'nullable|string|max:4000',
        ]);

        $taskCacheKey = 'v2node_probe_connectivity_task:' . $machine->id;
        $task = $this->probeCache()->get($taskCacheKey);
        if (!$task || !is_array($task) || !hash_equals((string) ($task['task_id'] ?? ''), (string) $params['task_id'])) {
            abort(404, 'Connectivity task not found');
        }

        $now = time();
        $expiresAt = (int) ($task['expires_at'] ?? ($now + 300));
        $ttl = max(60, $expiresAt - $now);
        $result = [
            'task_id' => (string) $params['task_id'],
            'machine_id' => (int) $machine->id,
            'machine_name' => (string) $machine->name,
            'summary' => $task['summary'] ?? [],
            'status' => (string) $params['status'],
            'message' => trim((string) ($params['message'] ?? '')),
            'latency_ms' => $params['latency_ms'] ?? null,
            'http_code' => $params['http_code'] ?? null,
            'logs' => trim((string) ($params['logs'] ?? '')),
            'created_at' => (int) ($task['created_at'] ?? $now),
            'updated_at' => $now,
            'tested_at' => $now,
            'expires_at' => $expiresAt,
        ];

        if ($result['message'] === '') {
            $result['message'] = $result['status'] === 'success'
                ? '节点机器本机真实协议测试成功'
                : '节点机器本机真实协议测试失败';
        }

        $this->probeCache()->put('v2node_probe_connectivity_result:' . $params['task_id'], $result, $ttl);
        $this->probeCache()->forget($taskCacheKey);

        return response()->json([
            'data' => 'success',
        ]);
    }

    public function runtimeTaskAck(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $params = $request->validate([
            'task_id' => 'required|string|max:64',
            'service' => 'required|string|in:ravel,gost',
            'action' => 'required|string|in:logs,status,start,stop,restart,reload',
            'status' => 'required|string|in:success,failed',
            'message' => 'nullable|string|max:255',
            'service_status' => 'nullable|string|max:64',
            'exit_code' => 'nullable|integer|min:-1|max:255',
            'logs' => 'nullable|string|max:32768',
            'executed_at' => 'nullable|integer|min:0',
        ]);

        $taskKey = 'v2node_probe_runtime_task:' . $machine->id;
        $task = $this->probeCache()->get($taskKey);
        if (!is_array($task) || !hash_equals((string) ($task['task_id'] ?? ''), (string) $params['task_id'])) {
            abort(404, 'Runtime task not found');
        }
        if ((string) ($task['service'] ?? '') !== (string) $params['service']
            || (string) ($task['action'] ?? '') !== (string) $params['action']) {
            abort(422, 'Runtime task result does not match request');
        }

        $now = time();
        $result = array_merge($task, [
            'status' => (string) $params['status'],
            'message' => trim((string) ($params['message'] ?? '')),
            'service_status' => trim((string) ($params['service_status'] ?? '')),
            'exit_code' => array_key_exists('exit_code', $params) ? (int) $params['exit_code'] : null,
            'logs' => (string) ($params['logs'] ?? ''),
            'executed_at' => (int) ($params['executed_at'] ?? $now),
            'updated_at' => $now,
        ]);
        if ($result['message'] === '') {
            $result['message'] = $result['status'] === 'success' ? '运行任务执行成功' : '运行任务执行失败';
        }

        $this->probeCache()->put(
            'v2node_probe_runtime_result:' . $params['task_id'],
            $result,
            600
        );
        $this->probeCache()->forget($taskKey);

        $status = \App\Services\MachineRuntimeTaskState::apply($this->decodeMachineStatus($machine), $result, $now);
        $machine->status = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $machine->save();
        $this->probeCache()->put($machine->statusCacheKey(), $status, 86400 * 7);

        return response()->json(['data' => 'success']);
    }

    private function buildProbeConnectivityTask(Machine $machine): ?array
    {
        $cacheKey = 'v2node_probe_connectivity_task:' . $machine->id;
        $task = $this->probeCache()->get($cacheKey);
        if (!$task || !is_array($task)) {
            return null;
        }

        $expiresAt = (int) ($task['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt <= time()) {
            $this->probeCache()->forget($cacheKey);
            return null;
        }

        return $task['connectivity_test_task'] ?? null;
    }

    private function normalizeNodeHealth(array $records): array
    {
        $normalized = [];
        foreach (array_slice($records, 0, 256) as $record) {
            if (!is_array($record)) {
                continue;
            }
            $nodeId = (int) ($record['node_id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $status = strtolower(trim((string) ($record['status'] ?? 'unknown')));
            if (!in_array($status, ['running', 'failed', 'unknown'], true)) {
                $status = 'unknown';
            }
            $normalized[] = [
                'node_id' => $nodeId,
                'protocol' => mb_substr(strtolower(trim((string) ($record['protocol'] ?? ''))), 0, 32),
                'status' => $status,
                'listen_ok' => !empty($record['listen_ok']) ? 1 : 0,
                'message' => mb_substr(trim((string) ($record['message'] ?? '')), 0, 255),
                'checked_at' => max(0, (int) ($record['checked_at'] ?? 0)),
                'server_port' => min(65535, max(0, (int) ($record['server_port'] ?? 0))),
            ];
        }
        return $normalized;
    }

    private function normalizeGostRuleHealth(array $records): array
    {
        $normalized = [];
        foreach (array_slice($records, 0, 512) as $record) {
            if (!is_array($record)) {
                continue;
            }
            $protocol = strtolower(trim((string) ($record['protocol'] ?? '')));
            $listenPort = (int) ($record['listen_port'] ?? 0);
            if (!in_array($protocol, ['tcp', 'udp'], true) || $listenPort < 1 || $listenPort > 65535) {
                continue;
            }
            $status = strtolower(trim((string) ($record['status'] ?? 'unknown')));
            if (!in_array($status, ['running', 'failed', 'inactive', 'unknown'], true)) {
                $status = 'unknown';
            }
            $targetStatus = strtolower(trim((string) ($record['target_status'] ?? '')));
            if (!in_array($targetStatus, ['', 'pending', 'reachable', 'unreachable', 'not_applicable'], true)) {
                $targetStatus = '';
            }
            $item = [
                'protocol' => $protocol,
                'listen_host' => mb_substr(trim((string) ($record['listen_host'] ?? '0.0.0.0')), 0, 255),
                'listen_port' => $listenPort,
                'status' => $status,
                'listen_ok' => !empty($record['listen_ok']) ? 1 : 0,
                'message' => mb_substr(trim((string) ($record['message'] ?? '')), 0, 255),
                'checked_at' => max(0, (int) ($record['checked_at'] ?? 0)),
                'target_status' => $targetStatus,
                'target_message' => mb_substr(trim((string) ($record['target_message'] ?? '')), 0, 255),
                'target_checked_at' => max(0, (int) ($record['target_checked_at'] ?? 0)),
            ];
            if (array_key_exists('target_ok', $record)) {
                $item['target_ok'] = !empty($record['target_ok']) ? 1 : 0;
            }
            $normalized[] = $item;
        }
        return $normalized;
    }

    private function buildProbeRuntimeTask(Machine $machine): ?array
    {
        $cacheKey = 'v2node_probe_runtime_task:' . $machine->id;
        $task = $this->probeCache()->get($cacheKey);
        if (!is_array($task)) {
            return null;
        }

        if ((int) ($task['expires_at'] ?? 0) <= time()) {
            $this->probeCache()->forget($cacheKey);
            return null;
        }

        return [
            'task_id' => (string) ($task['task_id'] ?? ''),
            'service' => (string) ($task['service'] ?? ''),
            'action' => (string) ($task['action'] ?? ''),
            'lines' => (int) ($task['lines'] ?? 200),
            'created_at' => (int) ($task['created_at'] ?? 0),
            'expires_at' => (int) ($task['expires_at'] ?? 0),
        ];
    }
}
