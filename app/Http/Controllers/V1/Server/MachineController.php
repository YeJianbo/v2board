<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    private const SIGNATURE_WINDOW_SECONDS = 300;

    private function panelSetting(string $key, $default = null)
    {
        $configValue = config('v2board.' . $key);
        if ($configValue !== null && $configValue !== '') {
            return $configValue;
        }

        $dbValue = DB::table('v2_settings')->where('name', $key)->value('value');
        if ($dbValue !== null && $dbValue !== '') {
            return $dbValue;
        }

        return $default;
    }

    private function authenticate(Request $request)
    {
        $signedMachine = $this->authenticateSignedRequest($request);
        if ($signedMachine) {
            return $signedMachine;
        }

        abort(401, 'Unauthorized: Signed probe request required');
    }

    private function authenticateSignedRequest(Request $request)
    {
        $machineId = $request->header('X-V2Node-Machine-Id');
        $timestamp = $request->header('X-V2Node-Timestamp');
        $nonce = $request->header('X-V2Node-Nonce');
        $signature = $request->header('X-V2Node-Signature');

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

        $machine = Machine::find((int) $machineId);
        if (!$machine || empty($machine->api_token)) {
            abort(401, 'Unauthorized: Invalid machine');
        }

        $bodyHash = hash('sha256', (string) $request->getContent());
        $path = '/' . ltrim($request->getPathInfo(), '/');
        $payload = implode("\n", [
            strtoupper($request->method()),
            $path,
            (string) $timestamp,
            $nonce,
            $bodyHash,
        ]);
        $expected = hash_hmac('sha256', $payload, $machine->api_token);

        if (!hash_equals($expected, strtolower((string) $signature))) {
            abort(401, 'Unauthorized: Invalid signature');
        }

        $nonceCacheKey = 'v2node_probe_nonce:' . $machine->id . ':' . hash('sha256', $nonce);
        if (Cache::has($nonceCacheKey)) {
            abort(401, 'Unauthorized: Replay detected');
        }
        Cache::put($nonceCacheKey, true, self::SIGNATURE_WINDOW_SECONDS);

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
        $remoteIp = $this->resolveRequestRemoteIp($request);
        $reportedIp = trim((string) ($extraStatus['ip'] ?? ($status['ip'] ?? '')));
        $primaryIp = $this->resolvePrimaryIp($reportedIp, $remoteIp);
        $previousPrimaryIp = trim((string) ($status['primary_ip'] ?? ''));

        if ($remoteIp !== '') {
            $status['remote_ip'] = $remoteIp;
        }
        if ($primaryIp !== '') {
            $status['primary_ip'] = $primaryIp;
        }
        $status['reported_at'] = time();

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

        $machine->status = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $machine->save();
    }

    private function resolveGeoByIp(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || $this->isPrivateIp($ip)) {
            return [];
        }

        return Cache::remember('machine_geo:' . $ip, 86400, function () use ($ip) {
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
            return (string) decrypt($value);
        } catch (\Throwable $e) {
            return preg_match('/^[A-Za-z0-9._-]{20,}$/', $value) ? $value : '';
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

    private function buildProbeRelayRules(Machine $machine): array
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

        $targetMachineIds = $servers->pluck('machine_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $targetMachines = Machine::query()
            ->whereIn('id', $targetMachineIds)
            ->get()
            ->keyBy('id');

        return $servers->map(function (ServerV2node $server) use ($targetMachines) {
            $targetMachine = $targetMachines->get((int) $server->machine_id);
            if (!$targetMachine) {
                return null;
            }

            $listenPort = (int) $server->port;
            if ($listenPort < 1 || $listenPort > 65535) {
                $listenPort = (int) $server->server_port;
            }
            $targetPort = (int) $server->server_port;
            if ($listenPort < 1 || $listenPort > 65535 || $targetPort < 1 || $targetPort > 65535) {
                return null;
            }

            $targetHost = $this->resolveMachineConnectHost($targetMachine);
            if ($targetHost === '') {
                return null;
            }

            return [
                'node_id' => (int) $server->id,
                'name' => (string) $server->name,
                'protocol' => strtolower(trim((string) $server->protocol)),
                'listen_host' => '0.0.0.0',
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'protocols' => $this->resolveV2nodeFirewallProtocols($server),
            ];
        })->filter()->values()->all();
    }

    private function buildMachineRelayRules(Machine $machine): array
    {
        $relayRules = is_array($machine->relay_rules) ? $machine->relay_rules : [];

        return collect($relayRules)
            ->map(function ($rule, $index) {
                if (!is_array($rule)) {
                    return null;
                }

                $listenHost = trim((string) ($rule['listen_host'] ?? '0.0.0.0'));
                $targetHost = trim((string) ($rule['target_host'] ?? ''));
                $listenPort = (int) ($rule['listen_port'] ?? 0);
                $targetPort = (int) ($rule['target_port'] ?? 0);
                $protocols = is_array($rule['protocols'] ?? null) ? $rule['protocols'] : [];
                $protocols = array_values(array_unique(array_filter(array_map(function ($protocol) {
                    return strtolower(trim((string) $protocol));
                }, $protocols), function ($protocol) {
                    return in_array($protocol, ['tcp', 'udp'], true);
                })));

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

    private function buildProbeFirewallRules(Machine $machine): array
    {
        return ServerV2node::query()
            ->where('machine_id', $machine->id)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->map(function (ServerV2node $server) {
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
                
                $apiKey = $this->panelSetting('server_token', '');

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
            'listen_ports',
            'virtualization',
            'tcp_congestion_control',
            'bbr_status',
            'docker_summary',
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
        
        $this->touchMachineHeartbeat($machine, $request, $status);

        return response()->json([
            'data' => 'success'
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
        if (!Cache::has($cacheKey)) {
            abort(401, 'Unauthorized: Invalid enrollment token');
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

        $nodes = ServerV2node::query()
            ->where('machine_id', $machine->id)
            ->orderBy('sort', 'ASC')
            ->get()
            ->map(function (ServerV2node $server) use ($apiHost, $apiKey) {
                return [
                    'ApiHost' => $apiHost,
                    'NodeID' => (int) $server->id,
                    'ApiKey' => $apiKey,
                    'Timeout' => 15,
                ];
            })
            ->values();

        $restartToken = Cache::get('v2node_probe_restart:' . $machine->id);
        $enableBbrToken = Cache::get('v2node_probe_enable_bbr:' . $machine->id);
        $ddnsHost = $this->resolveDdnsHost($machine);
        $currentIp = trim((string) ($status['primary_ip'] ?? $status['remote_ip'] ?? $status['ip'] ?? ''));

        return response()->json([
            'data' => $nodes,
            'restart_v2node_token' => $restartToken ?: '',
            'enable_bbr_token' => $enableBbrToken ?: '',
            'probe' => [
                'firewall_rules' => $this->buildProbeFirewallRules($machine),
                'relay' => [
                    'rules' => array_values(array_merge(
                        $this->buildMachineRelayRules($machine),
                        $this->buildProbeRelayRules($machine)
                    )),
                ],
                'ddns' => [
                    'enabled' => (bool) $machine->ddns_enabled,
                    'provider' => (string) ($machine->ddns_provider ?: 'cloudflare'),
                    'zone_name' => (string) ($machine->ddns_zone_name ?: ''),
                    'record_name' => (string) ($machine->ddns_record_name ?: ''),
                    'host' => $ddnsHost,
                    'record_type' => strtoupper((string) ($machine->ddns_record_type ?: 'A')),
                    'ttl' => (int) ($machine->ddns_ttl ?: 120),
                    'proxied' => (bool) $machine->ddns_proxied,
                    'api_token' => $this->decryptMachineDdnsApiToken($machine),
                    'current_ip' => $currentIp,
                    'last_synced_ip' => trim((string) ($status['ddns_synced_ip'] ?? '')),
                    'last_synced_at' => !empty($status['ddns_synced_at']) ? (int) $status['ddns_synced_at'] : 0,
                ],
            ],
        ]);
    }

    public function restartAck(Request $request)
    {
        $machine = $this->authenticate($request);
        $this->touchMachineHeartbeat($machine, $request);
        $params = $request->validate([
            'restart_token' => 'required|string',
        ]);

        $cacheKey = 'v2node_probe_restart:' . $machine->id;
        if (hash_equals((string) Cache::get($cacheKey, ''), (string) $params['restart_token'])) {
            Cache::forget($cacheKey);
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
        if (hash_equals((string) Cache::get($cacheKey, ''), (string) $params['enable_bbr_token'])) {
            Cache::forget($cacheKey);
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

        $machine->status = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $machine->save();

        return response()->json([
            'data' => 'success',
        ]);
    }
}
