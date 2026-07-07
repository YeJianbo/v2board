<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $servers = $this->filterServersByRequest($servers, $request);
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                $isNekoBox = strpos($flag, 'neko') !== false || strpos($flag, 'nb4a') !== false;
                if (strpos($flag, 'sing') !== false || $isNekoBox) {
                    $version = null;
                    $isBunCloudPinnedNekoBox = $isNekoBox && (
                        strpos($flag, 'buncloudpin') !== false ||
                        strpos($flag, 'buncloud-pin') !== false ||
                        strpos($flag, 'cert-pin') !== false
                    );
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if ($isNekoBox || (!is_null($version) && $version >= '1.12.0')) {
                        $class = new Singbox($user, $servers, [
                            'supports_certificate_public_key_sha256' => $isBunCloudPinnedNekoBox || (!$isNekoBox && !is_null($version) && version_compare($version, '1.13.0', '>=')),
                        ]);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function filterServersByRequest(array $servers, Request $request): array
    {
        $include = $this->normalizeFilterTerms(
            $request->input('filter', $request->input('include', ''))
        );
        $exclude = $this->normalizeFilterTerms($request->input('exclude', ''));

        if (!$include && !$exclude) {
            return $servers;
        }

        return array_values(array_filter($servers, function ($server) use ($include, $exclude) {
            if ($include && !$this->serverMatchesAnyTerm($server, $include)) {
                return false;
            }

            if ($exclude && $this->serverMatchesAnyTerm($server, $exclude)) {
                return false;
            }

            return true;
        }));
    }

    private function normalizeFilterTerms($value): array
    {
        $items = is_array($value) ? $value : [$value];
        $terms = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $terms = array_merge($terms, $this->normalizeFilterTerms($item));
                continue;
            }

            $parts = preg_split('/[\s,，|｜;；]+/u', (string)$item) ?: [];
            foreach ($parts as $part) {
                $term = $this->normalizeFilterText($part);
                if ($term === '' || $term === '*' || $term === 'all') {
                    continue;
                }
                $terms[] = $term;
            }
        }

        return array_values(array_unique(array_slice($terms, 0, 20)));
    }

    private function serverMatchesAnyTerm(array $server, array $terms): bool
    {
        $searchText = $this->buildServerSearchText($server);
        foreach ($terms as $term) {
            if ($term !== '' && strpos($searchText, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    private function buildServerSearchText(array $server): string
    {
        $type = (string)($server['protocol'] ?? $server['type'] ?? '');
        $fields = [
            $server['name'] ?? '',
            $server['host'] ?? '',
            $server['type'] ?? '',
            $server['protocol'] ?? '',
            $server['country'] ?? '',
            $server['country_code'] ?? '',
            $server['rate'] ?? '',
        ];

        foreach ($this->protocolAliases($type) as $alias) {
            $fields[] = $alias;
        }

        return $this->normalizeFilterText(implode(' ', array_filter(array_map('strval', $fields))));
    }

    private function protocolAliases(string $type): array
    {
        $type = $this->normalizeFilterText($type);
        $aliases = [
            'hysteria' => ['hy', 'hy2', 'hysteria2'],
            'hysteria2' => ['hy', 'hy2', 'hysteria'],
            'shadowsocks' => ['ss'],
            'vmess' => ['vmess'],
            'vless' => ['vless', 'reality'],
            'trojan' => ['trojan'],
            'tuic' => ['tuic'],
            'anytls' => ['anytls', 'any'],
        ];

        return $aliases[$type] ?? [];
    }

    private function normalizeFilterText(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        return $value;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
