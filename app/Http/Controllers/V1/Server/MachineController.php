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
            $this->enforceIpWhitelist($request, $signedMachine);
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

        $allowedIps = array_filter(array_map('trim', explode(',', $machine->host)));
        if (!$allowedIps) {
            return;
        }

        if (!in_array($request->ip(), $allowedIps, true)) {
            abort(401, 'Unauthorized: IP is not allowed');
        }
    }

    public function config(Request $request)
    {
        $machine = $this->authenticate($request);
        $nodes = [];

        // In local environment without a proper app_url, we just default to local
        $apiHost = $this->panelSetting('server_api_url')
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
        
        $status = [
            'cpu' => $data['cpu'] ?? 0,
            'mem' => $data['mem'] ?? 0,
            'disk' => $data['disk'] ?? 0,
            'net_out' => $data['net_tx'] ?? 0,
            'net_in' => $data['net_rx'] ?? 0,
            'uptime' => $data['uptime'] ?? 0,
            'ip' => $data['ip'] ?? $request->ip(),
            'remote_ip' => $request->ip(),
            'version' => $data['version'] ?? 'unknown'
        ];
        
        $machine->status = json_encode($status);
        $machine->save();

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

        $apiHost = rtrim((string) (
            $this->panelSetting('server_api_url')
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

        return response()->json([
            'data' => $nodes,
            'restart_v2node_token' => $restartToken ?: '',
        ]);
    }

    public function restartAck(Request $request)
    {
        $machine = $this->authenticate($request);
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
}
