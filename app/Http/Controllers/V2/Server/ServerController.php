<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\RavelCredentialService;
use App\Support\RavelConfig;
use Illuminate\Http\Request;
use App\Utils\Helper;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    private const DEFAULT_TLS_SERVER_NAME = 'genshin.hoyoverse.com';
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    private function objectOrEmpty($value)
    {
        if (empty($value)) {
            return (object) [];
        }

        return $value;
    }

    private function normalizeSettingsForV2node($value, string $protocol = '', int $tlsMode = 0)
    {
        if (empty($value)) {
            $value = [];
        }

        $settings = is_array($value) ? $value : (array) $value;
        if (
            ($tlsMode === 1 || $tlsMode === 2) &&
            in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'], true) &&
            empty($settings['server_name'])
        ) {
            $settings['server_name'] = self::DEFAULT_TLS_SERVER_NAME;
        }
        if (array_key_exists('server_port', $settings) && $settings['server_port'] !== null) {
            $settings['server_port'] = (string) $settings['server_port'];
        }

        return $settings;
    }

    private function panelSetting(string $key, $default = null)
    {
        $configValue = config('v2board.' . $key);
        if ($configValue !== null && $configValue !== '') {
            return $configValue;
        }

        $dbValue = Cache::remember('panel_setting:' . $key, 60, static function () use ($key) {
            return DB::table('v2_settings')->where('name', $key)->value('value');
        });
        if ($dbValue !== null && $dbValue !== '') {
            return $dbValue;
        }

        return $default;
    }

    private function etagMatches(Request $request, string $etag): bool
    {
        $header = (string)$request->header('If-None-Match', '');
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if (trim($candidate, "\"") === $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }

    public function __construct(Request $request)
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            $token = $request->input('token');
        }

        // token 为空（业务失败，不抛异常）
        if (empty($token)) {
            response()->json([
                'status' => 'fail',
                'message' => 'token is null'
            ], 200)->send();
            exit;
        }

        // token 错误
        if ($token !== $this->panelSetting('server_token', '')) {
            response()->json([
                'status' => 'fail',
                'message' => 'token is error'
            ], 200)->send();
            exit;
        }

        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, "v2node");

        // 节点不存在
        if (!$this->nodeInfo) {
            response()->json([
                'status' => 'fail',
                'message' => 'server is not exist'
            ], 200)->send();
            exit;
        }
    }

    // 后端获取配置
    public function config(Request $request)
    {
        Cache::put(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);

        $response = [
            'listen_ip' => $this->nodeInfo->listen_ip,
            'server_port' => $this->nodeInfo->server_port,
            'network' => $this->nodeInfo->network,
            'network_settings' => $this->objectOrEmpty($this->nodeInfo->network_settings),
            'protocol' => $this->nodeInfo->protocol,
            'tls' => $this->nodeInfo->tls,
            'tls_settings' => $this->normalizeSettingsForV2node(
                $this->nodeInfo->tls_settings,
                (string) $this->nodeInfo->protocol,
                (int) $this->nodeInfo->tls
            ),
            'encryption' => $this->nodeInfo->encryption,
            'encryption_settings' => $this->objectOrEmpty($this->nodeInfo->encryption_settings),
            'flow' => $this->nodeInfo->flow,
            'cipher' => $this->nodeInfo->cipher,
            'congestion_control' => $this->nodeInfo->congestion_control,
            'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
            'up_mbps' => $this->nodeInfo->up_mbps,
            'down_mbps' => $this->nodeInfo->down_mbps,
            'obfs' => $this->nodeInfo->obfs,
            'obfs_password' => $this->nodeInfo->obfs_password,
            'padding_scheme' => $this->nodeInfo->padding_scheme
        ];

        if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
        }

        if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
        }

        if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
            $response['ignore_client_bandwidth'] = true;
        } else {
            $response['ignore_client_bandwidth'] = false;
        }

        if ((string) $this->nodeInfo->protocol === 'ravel') {
            $response['ravel'] = RavelConfig::nodePayload($this->nodeInfo);
        }

        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60),
            'node_report_min_traffic' => (int)config('v2board.server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int)config('v2board.server_device_online_min_traffic', 0)
        ];

        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }

        $rsp = json_encode($response);
        $eTag = sha1($rsp);

        if ((string) $this->nodeInfo->protocol === 'ravel') {
            return response($response)->header('ETag', "\"{$eTag}\"")
                ->header('Cache-Control', 'private, no-store');
        }

        // 不使用 abort(304)，避免异常路径
        if ($this->etagMatches($request, $eTag)) {
            return response('', 304)->header('ETag', "\"{$eTag}\"");
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    public function user(Request $request)
    {
        Cache::put(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id);
        if ((string) $this->nodeInfo->protocol === 'ravel') {
            return response()->json(['users' => (new RavelCredentialService())->userPayloads($this->nodeInfo, $users)])
                ->header('Cache-Control', 'private, no-store');
        }
        $payload = ['users' => $users->map(function ($user) {
            return array_filter($user->toArray(), static function ($value) { return $value !== null; });
        })->values()->all()];
        $eTag = sha1(json_encode($payload));
        if ($this->etagMatches($request, $eTag)) {
            return response('', 304)->header('ETag', "\"{$eTag}\"");
        }
        return response()->json($payload)->header('ETag', "\"{$eTag}\"");
    }
}
