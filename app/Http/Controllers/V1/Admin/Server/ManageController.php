<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use App\Models\Machine;
use App\Models\User;
use App\Protocols\Singbox\Singbox;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ManageController extends Controller
{
    private const CONNECT_TIMEOUT_SECONDS = 3.0;
    private const TCPING_TIMEOUT_SECONDS = 6;
    private const CONNECTIVITY_TASK_TTL_SECONDS = 300;
    private const CONNECTIVITY_WAIT_SECONDS = 22;
    private const CONNECTIVITY_RESULT_POLL_INTERVAL_US = 500000;
    private const CONNECTIVITY_TEST_USER_EMAIL = 'system-connectivity-test@buncloud.invalid';
    private const CONNECTIVITY_TEST_USER_REMARK = '[system] connectivity-test-user';

    private function probeCache()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store(config('cache.default', 'file'));
        }
    }

    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ]);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '5m');
        $params = $request->only(
            'shadowsocks',
            'vmess',
            'vless',
            'trojan',
            'tuic',
            'hysteria',
            'anytls',
            'v2node'
        ) ?? [];
        if (empty($params)) {
            $params = [
                'shadowsocks' => $_POST['shadowsocks'] ?? null,
                'vmess'       => $_POST['vmess'] ?? null,
                'vless'       => $_POST['vless'] ?? null,
                'trojan'      => $_POST['trojan'] ?? null,
                'tuic'        => $_POST['tuic'] ?? null,
                'hysteria'    => $_POST['hysteria'] ?? null,
                'anytls'      => $_POST['anytls'] ?? null,
                'v2node'      => $_POST['v2node'] ?? null,
            ];
        }
        DB::beginTransaction();
        foreach ($params as $k => $v) {
            if (empty($v) || !is_array($v)) {
                continue;
            }
            $model = 'App\\Models\\Server' . ucfirst($k);
            foreach($v as $id => $sort) {
                $server = $model::find($id);
                if (!$server || !$server->update(['sort' => $sort])) {
                    DB::rollBack();
                    abort(500, '保存失败');
                }
            }
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

    public function testConnectivity(Request $request)
    {
        $params = $request->validate([
            'server_type' => 'required|string|in:shadowsocks,vmess,vless,trojan,tuic,hysteria,anytls,v2node',
            'server_id' => 'required|integer|min:1',
        ]);

        [$server, $normalizedType] = $this->resolveServerModel(
            (string) $params['server_type'],
            (int) $params['server_id']
        );

        if (!$server) {
            abort(404, '节点不存在');
        }

        $node = $this->extractNodeEndpoint($server, $normalizedType);
        if ($node['host'] === '' || $node['port'] <= 0) {
            abort(422, '节点地址或端口不完整，无法测试');
        }

        $machine = $this->resolveConnectivityMachine($server, $normalizedType);
        if (!$machine) {
            return response([
                'data' => $this->buildLegacyConnectivityPayload($server, $normalizedType, $node),
            ]);
        }

        $task = $this->dispatchConnectivityTestTask($server, $normalizedType, $machine, $node);
        $result = $this->waitForConnectivityTaskResult((string) $task['task_id']);
        if ($result) {
            return response([
                'data' => $this->formatMachineConnectivityPayload($task, $result),
            ]);
        }

        return response([
            'data' => $this->formatMachineConnectivityPayload($task, null),
        ]);
    }

    public function connectivityTestResult(Request $request)
    {
        $params = $request->validate([
            'task_id' => 'required|string',
        ]);

        $task = $this->probeCache()->get($this->connectivityTaskResultCacheKey((string) $params['task_id']));
        if (!$task || !is_array($task)) {
            abort(404, '测试任务不存在或已过期');
        }

        $cachedTask = $this->probeCache()->get($this->connectivityTaskCacheKey((int) ($task['machine_id'] ?? 0)));
        if (!$cachedTask || !is_array($cachedTask)) {
            $cachedTask = [
                'task_id' => (string) ($task['task_id'] ?? $params['task_id']),
                'machine_id' => (int) ($task['machine_id'] ?? 0),
                'machine_name' => (string) ($task['machine_name'] ?? ''),
                'summary' => $task['summary'] ?? [],
            ];
        }

        return response([
            'data' => $this->formatMachineConnectivityPayload($cachedTask, $task),
        ]);
    }

    private function resolveServerModel(string $serverType, int $serverId): array
    {
        $map = [
            'shadowsocks' => ServerShadowsocks::class,
            'vmess' => ServerVmess::class,
            'vless' => ServerVless::class,
            'trojan' => ServerTrojan::class,
            'tuic' => ServerTuic::class,
            'hysteria' => ServerHysteria::class,
            'anytls' => ServerAnytls::class,
            'v2node' => ServerV2node::class,
        ];

        $modelClass = $map[$serverType] ?? null;
        if (!$modelClass) {
            return [null, $serverType];
        }

        return [$modelClass::find($serverId), $serverType];
    }

    private function extractNodeEndpoint($server, string $serverType): array
    {
        $protocol = $serverType;
        if ($serverType === 'v2node') {
            $rawProtocol = strtolower(trim((string) ($server->protocol ?? 'vmess')));
            $protocol = $rawProtocol === 'hysteria2' ? 'hysteria' : $rawProtocol;
        }

        $networkSettings = is_array($server->network_settings ?? null) ? $server->network_settings : [];
        $tlsSettings = is_array($server->tls_settings ?? null) ? $server->tls_settings : [];

        return [
            'protocol' => $protocol,
            'host' => trim((string) ($server->host ?? '')),
            'port' => (int) ($server->port ?? 0),
            'server_port' => (int) ($server->server_port ?? 0),
            'network' => strtolower(trim((string) ($server->network ?? ($networkSettings['network'] ?? 'tcp')))),
            'sni' => trim((string) (
                $server->server_name
                ?? ($tlsSettings['server_name'] ?? '')
            )),
            'tls_mode' => (int) ($server->tls ?? 0),
        ];
    }

    private function buildLegacyConnectivityPayload($server, string $serverType, array $node): array
    {
        return [
            'mode' => 'local',
            'summary' => [
                'server_type' => $serverType,
                'server_id' => (int) $server->id,
                'protocol' => $node['protocol'],
                'host' => $node['host'],
                'port' => $node['port'],
                'server_port' => $node['server_port'],
                'network' => $node['network'],
                'sni' => $node['sni'],
                'tls_mode' => $node['tls_mode'],
            ],
            'local' => $this->buildLocalConnectivityResults($node),
            'tcping' => $this->runExternalTcping($node),
            'note' => '该节点未挂载在线机器，当前返回面板本机基础探测结果。',
        ];
    }

    private function resolveConnectivityMachine($server, string $serverType): ?Machine
    {
        $candidateIds = [];

        if ($serverType === 'v2node') {
            $relayMachineId = (int) ($server->relay_machine_id ?? 0);
            $machineId = (int) ($server->machine_id ?? 0);
            if ($relayMachineId > 0) {
                $candidateIds[] = $relayMachineId;
            }
            if ($machineId > 0) {
                $candidateIds[] = $machineId;
            }
        } else {
            $machineId = (int) ($server->machine_id ?? 0);
            if ($machineId > 0) {
                $candidateIds[] = $machineId;
            }
        }

        foreach (array_unique($candidateIds) as $machineId) {
            $machine = Machine::find($machineId);
            if ($machine) {
                return $machine;
            }
        }

        return null;
    }

    private function dispatchConnectivityTestTask($server, string $serverType, Machine $machine, array $node): array
    {
        $testUser = $this->ensureConnectivityTestUser($server);
        $config = $this->buildConnectivityTestConfig($server, $serverType, $testUser);
        $taskId = (string) Str::uuid();
        $now = time();
        $expiresAt = $now + self::CONNECTIVITY_TASK_TTL_SECONDS;
        $task = [
            'task_id' => $taskId,
            'machine_id' => (int) $machine->id,
            'machine_name' => (string) $machine->name,
            'server_type' => $serverType,
            'server_id' => (int) $server->id,
            'server_name' => (string) ($server->name ?? ''),
            'protocol' => (string) ($node['protocol'] ?? $serverType),
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'runner' => 'sing-box',
            'summary' => [
                'server_type' => $serverType,
                'server_id' => (int) $server->id,
                'protocol' => (string) ($node['protocol'] ?? $serverType),
                'host' => (string) ($node['host'] ?? ''),
                'port' => (int) ($node['port'] ?? 0),
                'server_port' => (int) ($node['server_port'] ?? 0),
                'network' => (string) ($node['network'] ?? ''),
                'sni' => (string) ($node['sni'] ?? ''),
                'tls_mode' => (int) ($node['tls_mode'] ?? 0),
            ],
            'connectivity_test_task' => [
                'task_id' => $taskId,
                'runner' => 'sing-box',
                'target_url' => 'https://cp.cloudflare.com/generate_204',
                'timeout_seconds' => 18,
                'expected_http_codes' => [200, 204, 301, 302],
                'config_format' => 'sing-box',
                'config_base64' => base64_encode(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ],
        ];

        $this->probeCache()->put(
            $this->connectivityTaskCacheKey((int) $machine->id),
            $task,
            self::CONNECTIVITY_TASK_TTL_SECONDS
        );
        $this->probeCache()->put(
            $this->connectivityTaskResultCacheKey($taskId),
            [
                'task_id' => $taskId,
                'machine_id' => (int) $machine->id,
                'machine_name' => (string) $machine->name,
                'summary' => $task['summary'],
                'status' => 'pending',
                'message' => '已下发到节点机器，等待真实协议拨测结果',
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $expiresAt,
            ],
            self::CONNECTIVITY_TASK_TTL_SECONDS
        );

        return $task;
    }

    private function ensureConnectivityTestUser($server): User
    {
        $groupIds = [];
        if (isset($server->group_id) && is_array($server->group_id)) {
            $groupIds = array_values(array_filter(array_map('intval', $server->group_id)));
        } elseif (is_array($server['group_id'] ?? null)) {
            $groupIds = array_values(array_filter(array_map('intval', $server['group_id'])));
        }

        $groupId = $groupIds[0] ?? null;
        $user = User::where('email', self::CONNECTIVITY_TEST_USER_EMAIL)->first();
        $payload = [
            'email' => self::CONNECTIVITY_TEST_USER_EMAIL,
            'password' => password_hash(Str::random(24), PASSWORD_DEFAULT),
            'password_algo' => null,
            'password_salt' => null,
            'balance' => 0,
            'commission_type' => 0,
            'commission_balance' => 0,
            't' => 0,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1073741824,
            'device_limit' => 1,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'uuid' => Helper::guid(true),
            'group_id' => $groupId,
            'plan_id' => null,
            'speed_limit' => null,
            'auto_renewal' => 0,
            'remind_expire' => 0,
            'remind_traffic' => 0,
            'token' => Helper::guid(),
            'expired_at' => time() + 31536000,
            'remarks' => self::CONNECTIVITY_TEST_USER_REMARK,
        ];

        if (!$user) {
            return User::create($payload);
        }

        $user->group_id = $groupId;
        $user->transfer_enable = 1073741824;
        $user->device_limit = 1;
        $user->banned = 0;
        $user->is_admin = 0;
        $user->is_staff = 0;
        $user->expired_at = time() + 31536000;
        $user->remarks = self::CONNECTIVITY_TEST_USER_REMARK;
        if (empty($user->uuid)) {
            $user->uuid = Helper::guid(true);
        }
        if (empty($user->token)) {
            $user->token = Helper::guid();
        }
        if (empty($user->password)) {
            $user->password = password_hash(Str::random(24), PASSWORD_DEFAULT);
        }
        $user->save();

        return $user;
    }

    private function buildConnectivityTestConfig($server, string $serverType, User $user): array
    {
        $serverPayload = $this->buildSingboxServerPayload($server, $serverType);
        $response = (new Singbox($user->toArray(), [$serverPayload]))->handle();
        $rawConfig = json_decode((string) $response->getContent(), true);

        if (!is_array($rawConfig)) {
            abort(500, '生成测试配置失败');
        }

        $targetOutbound = null;
        foreach (($rawConfig['outbounds'] ?? []) as $outbound) {
            $tag = (string) ($outbound['tag'] ?? '');
            if ($tag === '' || in_array($tag, ['DIRECT', '节点选择', '自动选择'], true)) {
                continue;
            }
            $targetOutbound = $outbound;
            break;
        }

        if (!$targetOutbound || empty($targetOutbound['tag'])) {
            abort(500, '未能生成可用的测试出站配置');
        }

        return [
            'log' => [
                'level' => 'warn',
            ],
            'dns' => [
                'servers' => [
                    [
                        'type' => 'local',
                        'tag' => 'local',
                    ],
                ],
                'final' => 'local',
            ],
            'inbounds' => [],
            'outbounds' => [
                $targetOutbound,
                [
                    'tag' => 'DIRECT',
                    'type' => 'direct',
                    'domain_resolver' => [
                        'server' => 'local',
                    ],
                ],
            ],
            'route' => [
                'auto_detect_interface' => true,
                'final' => (string) $targetOutbound['tag'],
            ],
        ];
    }

    private function buildSingboxServerPayload($server, string $serverType): array
    {
        $payload = $server->toArray();
        $payload['type'] = $serverType;

        if ($serverType === 'v2node') {
            $payload['type'] = 'v2node';
            $payload['protocol'] = strtolower(trim((string) ($payload['protocol'] ?? 'vmess')));
        }

        if (isset($payload['tls_settings']) && is_array($payload['tls_settings'])) {
            unset($payload['tls_settings']['private_key'], $payload['tls_settings']['ech_key']);
        }
        if (isset($payload['encryption_settings']) && is_array($payload['encryption_settings'])) {
            unset($payload['encryption_settings']['private_key']);
        }

        if (isset($payload['port']) && is_string($payload['port']) && strpos($payload['port'], '-') === false) {
            $payload['port'] = (int) $payload['port'];
        }

        return $payload;
    }

    private function waitForConnectivityTaskResult(string $taskId): ?array
    {
        $deadline = microtime(true) + self::CONNECTIVITY_WAIT_SECONDS;
        $cacheKey = $this->connectivityTaskResultCacheKey($taskId);

        while (microtime(true) < $deadline) {
            $result = $this->probeCache()->get($cacheKey);
            if (is_array($result) && in_array((string) ($result['status'] ?? ''), ['success', 'failed'], true)) {
                return $result;
            }
            usleep(self::CONNECTIVITY_RESULT_POLL_INTERVAL_US);
        }

        return null;
    }

    private function formatMachineConnectivityPayload(array $task, ?array $result): array
    {
        $summary = $task['summary'] ?? [];
        $status = (string) ($result['status'] ?? 'pending');
        $message = (string) ($result['message'] ?? '已下发到节点机器，等待真实协议拨测结果');
        $latencyMs = isset($result['latency_ms']) ? (int) $result['latency_ms'] : null;

        return [
            'mode' => 'machine',
            'task_id' => (string) ($task['task_id'] ?? ''),
            'summary' => $summary,
            'machine' => [
                'id' => (int) ($task['machine_id'] ?? 0),
                'name' => (string) ($task['machine_name'] ?? ''),
            ],
            'local' => [[
                'key' => 'machine',
                'label' => '机器真实拨测',
                'status' => $status,
                'message' => $message,
                'latency_ms' => $latencyMs,
            ]],
            'tcping' => null,
            'http_code' => isset($result['http_code']) ? (int) $result['http_code'] : null,
            'tested_at' => isset($result['tested_at']) ? (int) $result['tested_at'] : null,
            'logs' => (string) ($result['logs'] ?? ''),
            'runner' => (string) ($task['runner'] ?? 'sing-box'),
            'note' => $status === 'pending'
                ? '任务已下发到在线机器，正在执行真实协议拨测。'
                : '结果来自节点机器本机的真实代理出站测试。',
        ];
    }

    private function connectivityTaskCacheKey(int $machineId): string
    {
        return 'v2node_probe_connectivity_task:' . $machineId;
    }

    private function connectivityTaskResultCacheKey(string $taskId): string
    {
        return 'v2node_probe_connectivity_result:' . $taskId;
    }

    private function buildLocalConnectivityResults(array $node): array
    {
        $results = [];
        $protocol = $node['protocol'];
        $network = $node['network'];
        $isUdpProtocol = in_array($protocol, ['hysteria', 'tuic'], true) || in_array($network, ['udp', 'quic', 'kcp', 'mkcp'], true);
        $isTcpProtocol = !$isUdpProtocol;

        if ($isTcpProtocol) {
            $results[] = $this->runTcpConnectTest($node['host'], $node['port']);
        } else {
            $results[] = $this->runUdpSendTest($node['host'], $node['port']);
        }

        if ($node['tls_mode'] === 1 && $isTcpProtocol) {
            $results[] = $this->runTlsHandshakeTest($node['host'], $node['port'], $node['sni']);
        } elseif ($node['tls_mode'] === 2) {
            $results[] = [
                'key' => 'tls',
                'label' => 'Reality 握手',
                'status' => 'skipped',
                'message' => 'Reality 需要专用 ClientHello 指纹，面板仅保留基础端口探测',
                'latency_ms' => null,
            ];
        } elseif ($isUdpProtocol && in_array($protocol, ['hysteria', 'tuic'], true)) {
            $results[] = [
                'key' => 'tls',
                'label' => 'QUIC/TLS',
                'status' => 'skipped',
                'message' => '该协议基于 UDP/QUIC，面板当前不做完整握手，仅检测本机 UDP 发包能力',
                'latency_ms' => null,
            ];
        }

        return $results;
    }

    private function runTcpConnectTest(string $host, int $port): array
    {
        $address = sprintf('tcp://%s:%d', $host, $port);
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($socket === false) {
            return [
                'key' => 'tcp',
                'label' => 'TCP 建连',
                'status' => 'failed',
                'message' => $errstr !== '' ? $errstr : '连接失败',
                'latency_ms' => $latency,
            ];
        }

        fclose($socket);

        return [
            'key' => 'tcp',
            'label' => 'TCP 建连',
            'status' => 'success',
            'message' => '本机已成功建立 TCP 连接',
            'latency_ms' => $latency,
        ];
    }

    private function runUdpSendTest(string $host, int $port): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('udp://%s:%d', $host, $port),
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            return [
                'key' => 'udp',
                'label' => 'UDP 发包',
                'status' => 'failed',
                'message' => $errstr !== '' ? $errstr : 'UDP 目标不可达',
                'latency_ms' => null,
            ];
        }

        stream_set_timeout($socket, 1);
        $start = microtime(true);
        $written = @fwrite($socket, "buncloud-connectivity-check\n");
        $latency = (int) round((microtime(true) - $start) * 1000);
        fclose($socket);

        if ($written === false || $written === 0) {
            return [
                'key' => 'udp',
                'label' => 'UDP 发包',
                'status' => 'failed',
                'message' => '本机未能发出 UDP 探测包',
                'latency_ms' => $latency,
            ];
        }

        return [
            'key' => 'udp',
            'label' => 'UDP 发包',
            'status' => 'success',
            'message' => '本机已成功发出 UDP 探测包，UDP 无法像 TCP 一样确认远端握手',
            'latency_ms' => $latency,
        ];
    }

    private function runTlsHandshakeTest(string $host, int $port, string $sni): array
    {
        $peerName = $sni !== '' ? $sni : $host;
        $context = stream_context_create([
            'ssl' => [
                'SNI_enabled' => true,
                'peer_name' => $peerName,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'capture_peer_cert' => false,
            ],
        ]);

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tls://%s:%d', $host, $port),
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($socket === false) {
            return [
                'key' => 'tls',
                'label' => 'TLS 握手',
                'status' => 'failed',
                'message' => $errstr !== '' ? $errstr : 'TLS 握手失败',
                'latency_ms' => $latency,
            ];
        }

        fclose($socket);

        return [
            'key' => 'tls',
            'label' => 'TLS 握手',
            'status' => 'success',
            'message' => sprintf('已完成 TLS 握手，SNI=%s', $peerName),
            'latency_ms' => $latency,
        ];
    }

    private function runExternalTcping(array $node): array
    {
        $template = trim((string) config('v2board.tcping_api_url', ''));
        if ($template === '') {
            return [
                'status' => 'skipped',
                'message' => '未配置 tcping_api_url，已跳过墙外/GFW 视角探测',
                'data' => null,
            ];
        }

        $url = strtr($template, [
            '{host}' => $node['host'],
            '{port}' => (string) $node['port'],
            '{protocol}' => $node['protocol'],
        ]);

        try {
            $response = Http::timeout(self::TCPING_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($url);

            if (!$response->ok()) {
                return [
                    'status' => 'failed',
                    'message' => 'tcping 接口请求失败: HTTP ' . $response->status(),
                    'data' => null,
                ];
            }

            $data = $response->json();
            return [
                'status' => 'success',
                'message' => '已获取外部视角探测结果',
                'data' => is_array($data) ? $data : ['raw' => $response->body()],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'message' => 'tcping 接口调用失败: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }
}
