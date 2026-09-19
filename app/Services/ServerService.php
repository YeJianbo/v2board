<?php

namespace App\Services;

use App\Models\ServerHysteria;
use App\Models\ServerLog;
use App\Models\ServerRoute;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerV2node;
use App\Models\User;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerAnytls;
use App\Models\RavelCredential;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerService
{
    private $availableServerCollections;

    private function availableServerCollection(string $type)
    {
        if ($this->availableServerCollections === null) {
            $loaders = [
                'vless' => static function () {
                    return ServerVless::orderBy('sort', 'ASC')->get();
                },
                'vmess' => static function () {
                    return ServerVmess::orderBy('sort', 'ASC')->get();
                },
                'trojan' => static function () {
                    return ServerTrojan::orderBy('sort', 'ASC')->get();
                },
                'tuic' => static function () {
                    return ServerTuic::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'hysteria' => static function () {
                    return ServerHysteria::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'shadowsocks' => static function () {
                    return ServerShadowsocks::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'anytls' => static function () {
                    return ServerAnytls::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'v2node' => static function () {
                    return ServerV2node::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
            ];
            $cacheKeys = [];
            foreach (array_keys($loaders) as $serverType) {
                $cacheKeys[$serverType] = 'servers_' . $serverType;
            }
            $cachedCollections = Cache::many(array_values($cacheKeys));
            $this->availableServerCollections = [];

            foreach ($loaders as $serverType => $loader) {
                $cacheKey = $cacheKeys[$serverType];
                $collection = $cachedCollections[$cacheKey] ?? null;
                if ($collection === null) {
                    $collection = $loader();
                    Cache::put($cacheKey, $collection, 60);
                }
                $this->availableServerCollections[$serverType] = $collection;
            }
        }

        return $this->availableServerCollections[$type];
    }

    private function serverStatuses(array $servers): array
    {
        $definitions = [];
        $cacheKeys = [];
        foreach ($servers as $index => $server) {
            $serverType = strtoupper((string) ($server['type'] ?? ''));
            $serverIds = array_values(array_unique(array_filter(
                $this->serverStatusIds($server),
                static function ($serverId) {
                    return $serverId !== null && $serverId !== '' && (int) $serverId > 0;
                }
            )));
            $definitions[$index] = [
                'type' => $serverType,
                'ids' => $serverIds,
            ];

            if ($serverType === '') {
                continue;
            }
            foreach ($serverIds as $serverId) {
                $cacheKeys[] = CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $serverId);
                $cacheKeys[] = CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $serverId);
                $cacheKeys[] = CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $serverId);
            }
        }
        $statusValues = $cacheKeys ? Cache::many(array_values(array_unique($cacheKeys))) : [];

        $statuses = [];
        foreach ($definitions as $index => $definition) {
            $online = 0;
            $lastCheckAt = 0;
            $lastPushAt = 0;
            foreach ($definition['ids'] as $serverId) {
                $serverType = $definition['type'];
                $online = max(
                    $online,
                    (int) ($statusValues[CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $serverId)] ?? 0)
                );
                $lastCheckAt = max(
                    $lastCheckAt,
                    (int) ($statusValues[CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $serverId)] ?? 0)
                );
                $lastPushAt = max(
                    $lastPushAt,
                    (int) ($statusValues[CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $serverId)] ?? 0)
                );
            }

            $statuses[$index] = [
                'online' => $online,
                'last_check_at' => $lastCheckAt,
                'last_push_at' => $lastPushAt,
            ];
        }

        return $statuses;
    }

    private function serverStatusIds(array $server): array
    {
        return [
            $server['id'] ?? null,
            $server['parent_id'] ?? null
        ];
    }

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

    public function getAvailableVless(User $user): array
    {
        $servers = [];
        $server = $this->availableServerCollection('vless');
        foreach ($server as $key => $v) {
            if (!$v['show']) continue;
            $server[$key]['type'] = 'vless';
            if (!in_array($user->group_id, $server[$key]['group_id'])) continue;
            if (strpos($server[$key]['port'], '-') !== false) {
                $server[$key]['port'] = Helper::randomPort($server[$key]['port']);
            }
            if (isset($server[$key]['tls_settings'])) {
                $server[$key]['tls_settings'] = array_diff_key(
                    $server[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($server, $key) {
                        return isset($server[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($server[$key]['encryption_settings'])) {
                if (isset($server[$key]['encryption_settings']['private_key'])) {
                    $server[$key]['encryption_settings'] = array_diff_key($server[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $server[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableVmess(User $user): array
    {
        $servers = [];
        $vmess = $this->availableServerCollection('vmess');
        foreach ($vmess as $key => $v) {
            if (!$v['show']) continue;
            $vmess[$key]['type'] = 'vmess';
            if (!in_array($user->group_id, $vmess[$key]['group_id'])) continue;
            if (strpos($vmess[$key]['port'], '-') !== false) {
                $vmess[$key]['port'] = Helper::randomPort($vmess[$key]['port']);
            }
            $servers[] = $vmess[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableTrojan(User $user): array
    {
        $servers = [];
        $trojan = $this->availableServerCollection('trojan');
        foreach ($trojan as $key => $v) {
            if (!$v['show']) continue;
            $trojan[$key]['type'] = 'trojan';
            if (!in_array($user->group_id, $trojan[$key]['group_id'])) continue;
            if (strpos($trojan[$key]['port'], '-') !== false) {
                $trojan[$key]['port'] = Helper::randomPort($trojan[$key]['port']);
            }
            $servers[] = $trojan[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableTuic(User $user)
    {
        $availableServers = [];
        $servers = $this->availableServerCollection('tuic');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            $servers[$key]['type'] = 'tuic';
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableHysteria(User $user)
    {
        $availableServers = [];
        $servers = $this->availableServerCollection('hysteria');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            $servers[$key]['type'] = 'hysteria';
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $servers[$key]['server_key'] = Helper::getServerKey($servers[$key]['created_at'], 16);
            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableShadowsocks(User $user)
    {
        $servers = [];
        $shadowsocks = $this->availableServerCollection('shadowsocks');
        foreach ($shadowsocks as $key => $v) {
            if (!$v['show']) continue;
            $shadowsocks[$key]['type'] = 'shadowsocks';
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $shadowsocks[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($shadowsocks[$v['parent_id']])) {
                $shadowsocks[$key]['created_at'] = $shadowsocks[$v['parent_id']]['created_at'];
            }
            if ($v['obfs'] === 'http') {
                $shadowsocks[$key]['obfs'] = 'http';
                $shadowsocks[$key]['obfs-host'] = $v['obfs_settings']['host'];
                $shadowsocks[$key]['obfs-path'] = $v['obfs_settings']['path'];
            }
            $servers[] = $shadowsocks[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableAnyTLS(User $user)
    {
        $servers = [];
        $anytls = $this->availableServerCollection('anytls');
        foreach ($anytls as $key => $v) {
            if (!$v['show']) continue;
            $anytls[$key]['type'] = 'anytls';
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $anytls[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($anytls[$v['parent_id']])) {
                $anytls[$key]['created_at'] = $anytls[$v['parent_id']]['created_at'];
            }
            $servers[] = $anytls[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableV2node(User $user)
    {
        $servers = [];
        $v2node = $this->availableServerCollection('v2node');
        foreach ($v2node as $key => $v) {
            if (!$v['show']) continue;
            $v2node[$key]['type'] = 'v2node';
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (isset($v2node[$v['parent_id']])) {
                $v2node[$key]['created_at'] = $v2node[$v['parent_id']]['created_at'];
            }
            if (isset($v2node[$key]['tls_settings'])) {
                $v2node[$key]['tls_settings'] = array_diff_key(
                    $v2node[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($v2node, $key) {
                        return isset($v2node[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($v2node[$key]['encryption_settings'])) {
                if (isset($v2node[$key]['encryption_settings']['private_key'])) {
                    $v2node[$key]['encryption_settings'] = array_diff_key($v2node[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $payload = $v2node[$key]->toArray();
            if ((string) $v2node[$key]['protocol'] === 'ravel') {
                $credentialService = new RavelCredentialService();
                $payload['ravel_credentials'] = $credentialService
                    ->credentialsForSubscription($v2node[$key], $user);
            }
            $servers[] = $payload;
        }
        return $servers;
    }

    public function getAvailableServers(User $user, bool $withStatus = true)
    {
        $servers = array_merge(
            $this->getAvailableShadowsocks($user),
            $this->getAvailableVmess($user),
            $this->getAvailableTrojan($user),
            $this->getAvailableTuic($user),
            $this->getAvailableHysteria($user),
            $this->getAvailableVless($user),
            $this->getAvailableAnyTLS($user),
            $this->getAvailableV2node($user)
        );
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        $statuses = $withStatus ? $this->serverStatuses($servers) : [];
        foreach ($servers as $index => &$server) {
            if (strpos($server['port'], '-')) {
                $server['mport'] = (string)$server['port'];
            } else {
                $server['port'] = (int)$server['port'];
            }
            if (!$withStatus) {
                continue;
            }
            $status = $statuses[$index] ?? [
                'last_check_at' => 0,
                'last_push_at' => 0,
            ];
            $lastCheckAt = max((int) ($server['last_check_at'] ?? 0), $status['last_check_at']);
            $lastPushAt = $status['last_push_at'];
            $server['last_check_at'] = $lastCheckAt;
            $server['last_push_at'] = $lastPushAt;
            $server['is_online'] = max($lastCheckAt, $lastPushAt) > 0 ? 1 : 0;
            $server['cache_key'] = "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}";
        }
        unset($server);

        return app(SubscriptionEntryService::class)->apply($servers);
    }

    public function getAvailableUsers($groupId)
    {
        return User::whereIn('group_id', $groupId)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();
    }

    public function log(int $userId, int $serverId, int $u, int $d, float $rate, string $method)
    {
        if (($u + $d) < 10240) return true;
        $timestamp = strtotime(date('Y-m-d'));
        $serverLog = ServerLog::where('log_at', '>=', $timestamp)
            ->where('log_at', '<', $timestamp + 3600)
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('rate', $rate)
            ->where('method', $method)
            ->first();
        if ($serverLog) {
            try {
                $serverLog->increment('u', $u);
                $serverLog->increment('d', $d);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $serverLog = new ServerLog();
            $serverLog->user_id = $userId;
            $serverLog->server_id = $serverId;
            $serverLog->u = $u;
            $serverLog->d = $d;
            $serverLog->rate = $rate;
            $serverLog->log_at = $timestamp;
            $serverLog->method = $method;
            return $serverLog->save();
        }
    }

    public function getAllShadowsocks()
    {
        $servers = ServerShadowsocks::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'shadowsocks';
        }
        return $servers;
    }

    public function getAllVMess()
    {
        $servers = ServerVmess::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vmess';
        }
        return $servers;
    }

    public function getAllVLess()
    {
        $servers = ServerVless::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vless';
        }
        return $servers;
    }

    public function getAllTrojan()
    {
        $servers = ServerTrojan::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'trojan';
        }
        return $servers;
    }

    public function getAllTuic()
    {
        $servers = ServerTuic::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'tuic';
        }
        return $servers;
    }

    public function getAllHysteria()
    {
        $servers = ServerHysteria::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'hysteria';
        }
        return $servers;
    }

    public function getAllAnyTLS()
    {
        $servers = ServerAnytls::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'anytls';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }
        }
        return $servers;
    }

    public function getAllV2node()
    {
        $servers = ServerV2node::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        $ravelIds = array_column(array_filter($servers, static function ($server) {
            return ($server['protocol'] ?? '') === 'ravel';
        }), 'id');
        $credentialSummaries = !$ravelIds ? collect() : RavelCredential::query()
            ->whereIn('server_id', $ravelIds)
            ->select([
                'server_id',
                'key_version',
                'revoked_at',
                'not_before',
                'not_after',
            ])
            ->get()
            ->groupBy('server_id');
        $now = time();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'v2node';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }
            if ((string) ($v['protocol'] ?? '') === 'ravel') {
                $credentials = $credentialSummaries->get((int) $v['id'], collect());
                $servers[$k]['ravel_credential_summary'] = [
                    'total' => $credentials->count(),
                    'active' => $credentials->filter(function ($credential) use ($now) {
                        return $credential->revoked_at === null
                            && (int) $credential->not_before <= $now
                            && (int) $credential->not_after > $now;
                    })->count(),
                    'revoked' => $credentials->whereNotNull('revoked_at')->count(),
                    'latest_key_version' => (int) $credentials->max('key_version'),
                ];
            }

            $apiHost = $this->panelSetting('server_api_url')
                ?: $this->panelSetting('app_url')
                ?: config('app.url');
            $apiKey = $this->panelSetting('server_token', '');
            $nodeId = (int) $v['id'];
            $apiHost = rtrim((string) $apiHost, '/');
            $apiKey = (string) $apiKey;

            if ($apiHost !== '' && $apiKey !== '') {
                $installerUrl = (string) config('probe.installer_url');
                $apiHostArg = escapeshellarg($apiHost);
                $apiKeyArg = escapeshellarg($apiKey);
                $servers[$k]['install_command'] = sprintf(
                    'curl -fL --proto \'=https\' --tlsv1.2 %s -o ravel-install.sh && bash ravel-install.sh --api-host %s --node-id %d --api-key %s',
                    escapeshellarg($installerUrl),
                    $apiHostArg,
                    $nodeId,
                    $apiKeyArg
                );
            } else {
                $servers[$k]['install_command'] = '';
                $servers[$k]['install_command_missing_reason'] = '请先在系统设置中配置站点 URL 和通讯密钥';
            }
        }
        return $servers;
    }

    private function mergeData(&$servers)
    {
        $statuses = $this->serverStatuses($servers);
        foreach ($servers as $k => $v) {
            $status = $statuses[$k] ?? [
                'online' => 0,
                'last_check_at' => 0,
                'last_push_at' => 0,
            ];
            $online = $status['online'];
            $lastCheckAt = $status['last_check_at'];
            $lastPushAt = $status['last_push_at'];

            $servers[$k]['online'] = (int) $online;
            $servers[$k]['last_check_at'] = $lastCheckAt;
            $servers[$k]['last_push_at'] = $lastPushAt;
            $lastActiveAt = max($lastCheckAt, $lastPushAt);
            $servers[$k]['is_online'] = $lastActiveAt > 0 ? 1 : 0;
            if (!$servers[$k]['is_online']) {
                $servers[$k]['available_status'] = 0;
            } else if (!$lastPushAt) {
                $servers[$k]['available_status'] = 1;
            } else {
                $servers[$k]['available_status'] = 2;
            }
        }
    }

    public function getAllServers()
    {
        $servers = array_merge(
            $this->getAllShadowsocks(),
            $this->getAllVMess(),
            $this->getAllTrojan(),
            $this->getAllTuic(),
            $this->getAllHysteria(),
            $this->getAllVLess(),
            $this->getAllAnyTLS(),
            $this->getAllV2node()
        );
        $this->mergeData($servers);
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        return $servers;
    }

    public function getNodeStatusSummary(): array
    {
        $nodes = $this->getNodeStatusDetails();
        return [
            'total' => count($nodes),
            'online' => count(array_filter($nodes, static fn ($node) => $node['is_online'])),
        ];
    }

    public function getNodeStatusDetails(): array
    {
        $servers = [];
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            foreach ($this->availableServerCollection($type) as $server) {
                $servers[] = [
                    'type' => $type,
                    'id' => $server['id'],
                    'parent_id' => $server['parent_id'] ?? null,
                    'key' => $type . ':' . $server['id'],
                    'name' => (string) ($server['name'] ?? ''),
                    'protocol' => (string) ($server['protocol'] ?? $type),
                    'host' => (string) ($server['host'] ?? ''),
                    'port' => $server['port'] ?? '',
                    'show' => (bool) ($server['show'] ?? false),
                    'sort' => (int) ($server['sort'] ?? 0),
                ];
            }
        }

        // Match node management: children inherit their same-protocol parent's heartbeat.
        $statuses = $this->serverStatuses($servers);
        foreach ($servers as $index => &$server) {
            $status = $statuses[$index];
            $server['last_active_at'] = max($status['last_check_at'], $status['last_push_at']);
            $server['is_online'] = $server['last_active_at'] > 0;
        }
        unset($server);
        usort($servers, static fn ($a, $b) => [$a['sort'], $a['type'], $a['id']] <=> [$b['sort'], $b['type'], $b['id']]);
        return $servers;
    }

    public function getRoutes(array $routeIds)
    {
        $routeIds = array_map('intval', $routeIds);
        $order = implode(',', $routeIds);
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->orderByRaw("FIELD(id, $order)")
            ->get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return $routes;
    }

    public function getServer($serverId, $serverType)
    {
        switch ($serverType) {
            case 'v2node':
                return ServerV2node::find($serverId);
            case 'vmess':
                return ServerVmess::find($serverId);
            case 'shadowsocks':
                return ServerShadowsocks::find($serverId);
            case 'trojan':
                return ServerTrojan::find($serverId);
            case 'tuic':
                return ServerTuic::find($serverId);
            case 'hysteria':
                return ServerHysteria::find($serverId);
            case 'vless':
                return ServerVless::find($serverId);
            case 'anytls':
                return ServerAnytls::find($serverId);
            default:
                return false;
        }
    }
}
