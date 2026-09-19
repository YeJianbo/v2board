<?php

namespace App\Protocols;

use App\Models\SubscribeTemplate;
use App\Services\SubscriptionResponse;
use App\Utils\Helper;
use App\Support\RavelSubscription;
use Symfony\Component\Yaml\Yaml;

class ClashMeta
{
    private const CLASH_META_FOR_ANDROID_ANYTLS_MIN_VERSION = '2.11.8';
    private const MIHOMO_ANYTLS_MIN_VERSION = '1.19.3';
    public $flag = 'meta';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');
        $appUrl = config('v2board.app_url');
        $config = SubscribeTemplate::parseYaml($this->templateName());
        $proxy = [];
        $proxies = [];
        $clientInfo = $this->getClientInfo();

        foreach ($servers as $item) {
            // Singbox-style inline adaptation: unwrap v2node
            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            $handled = false;
            switch ($item['type']) {
                case 'shadowsocks':
                    $proxy[] = self::buildShadowsocks($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'vmess':
                    $proxy[] = self::buildVmess($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'vless':
                    $proxy[] = self::buildVless($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'trojan':
                    $proxy[] = self::buildTrojan($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'tuic':
                    $proxy[] = self::buildTuic($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'anytls':
                    if (!$this->shouldBuildAnyTLS($item, $clientInfo)) {
                        break;
                    }
                    $proxy[] = self::buildAnyTLS($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'hysteria':
                    $proxy[] = self::buildHysteria($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'hysteria2':
                    $proxy[] = $this->buildHysteria2($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
                case 'ravel':
                    $ravel = RavelSubscription::mihomo($item);
                    if (!$ravel) {
                        break;
                    }
                    $proxy[] = $ravel;
                    $proxies[] = $item['name'];
                    $handled = true;
                    break;
            }

        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        $config['proxy-groups'] = $this->resolveProxyGroups(
            is_array($config['proxy-groups'] ?? null) ? $config['proxy-groups'] : [],
            $proxies
        );
        // Force the current subscription domain to be a direct rule
        //$subsDomain = $_SERVER['HTTP_HOST'];
        //if ($subsDomain) {
        //    array_unshift($config['rules'], "DOMAIN,{$subsDomain},DIRECT");
        //}

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return response($yaml, 200, array_merge(SubscriptionResponse::headers($user, $appName, $appUrl), [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Content-Length' => strlen($yaml),
            'Cache-Control' => 'private, no-store, no-transform',
            'X-Accel-Buffering' => 'no',
        ]));
    }

    protected function templateName(): string
    {
        return 'clashmeta';
    }

    private function getClientInfo(): array
    {
        $flag = strtolower((string)(request()->input('flag') ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        $clientName = null;
        $clientVersion = null;

        foreach (['clashmetaforandroid', 'verge', 'flclash', 'nyanpasu', 'mihomo', 'meta', 'clash'] as $name) {
            if (strpos($flag, $name) !== false) {
                $clientName = $name;
                $pattern = '/' . preg_quote($name, '/') . '[\/\s\-_]+v?(\d+(?:\.\d+){0,3})/i';
                if (preg_match($pattern, $flag, $matches)) {
                    $clientVersion = $matches[1];
                }
                break;
            }
        }

        if (!$clientVersion && preg_match('/(?:\/|\s|_|-)v?(\d+(?:\.\d+){1,3})/i', $flag, $matches)) {
            $clientVersion = $matches[1];
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion,
        ];
    }

    private function shouldBuildAnyTLS(array $server, array $clientInfo): bool
    {
        if ($this->isAnyTLSReality($server)) {
            return false;
        }

        if (($clientInfo['name'] ?? null) === 'clashmetaforandroid') {
            return !empty($clientInfo['version'])
                && version_compare($clientInfo['version'], self::CLASH_META_FOR_ANDROID_ANYTLS_MIN_VERSION, '>=');
        }

        if (in_array($clientInfo['name'] ?? null, ['meta', 'mihomo'], true) && !empty($clientInfo['version'])) {
            return version_compare($clientInfo['version'], self::MIHOMO_ANYTLS_MIN_VERSION, '>=');
        }

        return true;
    }

    private function isAnyTLSReality(array $server): bool
    {
        if (isset($server['tls']) && (int)$server['tls'] === 2) {
            return true;
        }

        $tlsSettings = $server['tls_settings'] ?? [];
        return is_array($tlsSettings) && (
            !empty($tlsSettings['public_key']) ||
            !empty($tlsSettings['short_id'])
        );
    }

    public static function buildShadowsocks($password, $server)
    {
        if ($server['cipher'] === '2022-blake3-aes-128-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 16);
            $userKey = Helper::uuidToBase64($password, 16);
            $password = "{$serverKey}:{$userKey}";
        }
        if ($server['cipher'] === '2022-blake3-aes-256-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 32);
            $userKey = Helper::uuidToBase64($password, 32);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'ss';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['cipher'] = $server['cipher'];
        $array['password'] = $password;
        $array['udp'] = true;
        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs';
            $plugin_opts = [
                'mode' => 'http'
            ];
            if (isset($server['obfs-host'])) {
                $plugin_opts['host'] = $server['obfs-host'];
            } else {
                $plugin_opts['host'] = '';
            }
            if (isset($server['obfs-path'])) {
                $plugin_opts['path'] = $server['obfs-path'];
            }
            $array['plugin-opts'] = $plugin_opts;
        } else if ((($server['network'] ?? null) === 'http') && isset(($server['network_settings'] ?? [])['Host'])) {
            // Fallback like Singbox: treat http obfs specified via network_settings
            $array['plugin'] = 'obfs';
            $networkSettings = $server['network_settings'];
            $plugin_opts = [
                'mode' => 'http',
                'host' => ($networkSettings['Host'] ?? ''),
            ];
            if (isset($networkSettings['path'])) {
                $plugin_opts['path'] = $networkSettings['path'];
            }
            $array['plugin-opts'] = $plugin_opts;
        }
        return $array;
    }

    public static function buildVmess($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['alterId'] = 0;
        $array['cipher'] = 'auto';
        $array['udp'] = true;

        if (!empty($server['tls'])) {
            $array['tls'] = true;
            $tlsSettings = $server['tlsSettings'] ?? ($server['tls_settings'] ?? null);
            if ($tlsSettings) {
                if (isset($tlsSettings['allowInsecure']) && !empty($tlsSettings['allowInsecure']))
                    $array['skip-cert-verify'] = ($tlsSettings['allowInsecure'] ? true : false);
                if (isset($tlsSettings['serverName']) && !empty($tlsSettings['serverName']))
                    $array['servername'] = $tlsSettings['serverName'];
                $array = Helper::appendCertificateFingerprint($array, is_array($tlsSettings) ? $tlsSettings : []);
                if (!empty($tlsSettings['ech'])) {
                    if ($tlsSettings['ech'] === 'cloudflare') {
                        $array['ech-opts'] = [
                            'enable' => true,
                            'query-server-name' => 'cloudflare-ech.com'
                        ];
                    } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                        $array['ech-opts'] = [
                            'enable' => true,
                            'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']]
                        ];
                    }
                }
            }
        }
        $network = $server['network'] ?? null;
        if ($network === 'tcp') {
            $tcpSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['network'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['http-opts']['headers']['Host'] = $tcpSettings['header']['request']['headers']['Host'];
                if (isset($tcpSettings['header']['request']['path'])) $array['http-opts']['path'] = $tcpSettings['header']['request']['path'];
            }
        }
        if ($network === 'ws') {
            $array['network'] = 'ws';
            $wsSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? null);
            if ($wsSettings) {
                $array['ws-opts'] = [];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['ws-opts']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
                if (isset($wsSettings['security'])) 
                    $array['cipher'] = $wsSettings['security'];
            }
        }
        if ($network === 'grpc') {
            $array['network'] = 'grpc';
            $grpcSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? null);
            if ($grpcSettings) {
                $array['grpc-opts'] = [];
                if (isset($grpcSettings['serviceName'])) $array['grpc-opts']['grpc-service-name'] = $grpcSettings['serviceName'];
            }
        }

        return $array;
    }

    public static function buildVless($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'vless';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['udp'] = true;

        if (!empty($server['flow'])) {
            $array['flow'] = $server['flow'];
        }

        if ($server['tls']) {
            $array['tls'] = true;
            $tlsSettings = $server['tls_settings'] ?? [];
            $array['skip-cert-verify'] = ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false;
            $array['client-fingerprint'] = !empty($tlsSettings['fingerprint']) ? $tlsSettings['fingerprint'] : 'chrome';
            if ($tlsSettings) {
                if (isset($tlsSettings['server_name']) && !empty($tlsSettings['server_name']))
                   $array['servername'] = $tlsSettings['server_name'];
                if ($server['tls'] == 2) {
                   $array['reality-opts'] = [];
                   $array['reality-opts']['public-key'] = $tlsSettings['public_key'];
                   $array['reality-opts']['short-id'] = $tlsSettings['short_id'];
                }
                if (!empty($tlsSettings['ech'])) {
                    if ($tlsSettings['ech'] === 'cloudflare') {
                        $array['ech-opts'] = [
                            'enable' => true,
                            'query-server-name' => 'cloudflare-ech.com'
                        ];
                    } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                        $array['ech-opts'] = [
                            'enable' => true,
                            'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']]
                        ];
                    }
                }
            }
            if ((int) $server['tls'] !== 2) {
                $array = Helper::appendCertificateFingerprint($array, $tlsSettings);
            }
        }

        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['network'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['http-opts']['headers']['Host'] = $tcpSettings['header']['request']['headers']['Host'];
                if (isset($tcpSettings['header']['request']['path'])) $array['http-opts']['path'] = $tcpSettings['header']['request']['path'];
            }
        }

        if ($server['network'] === 'ws') {
            $array['network'] = 'ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                $array['ws-opts'] = [];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['ws-opts']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
            }
        }
        if ($server['network'] === 'grpc') {
            $array['network'] = 'grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                $array['grpc-opts'] = [];
                if (isset($grpcSettings['serviceName'])) $array['grpc-opts']['grpc-service-name'] = $grpcSettings['serviceName'];
            }
        }
        if ($server['network'] === 'xhttp') {
            $array['network'] = 'xhttp';
            if ($server['network_settings']) {
                $xhttpSettings = $server['network_settings'];
                $array['xhttp-opts'] = [];
                if (isset($xhttpSettings['path'])) $array['xhttp-opts']['path'] = $xhttpSettings['path'];
                if (isset($xhttpSettings['host'])) $array['xhttp-opts']['host'] = $xhttpSettings['host'];
                if (isset($xhttpSettings['mode'])) $array['xhttp-opts']['mode'] = $xhttpSettings['mode'];
                // 暂不支持extra
                //if (isset($xhttpSettings['extra'])) {
                    //$array['xhttp-opts']['headers'] = $xhttpSettings['extra']['headers'] ?? [];
                    //if (isset($xhttpSettings['extra']['xmux'])) {
                    //    $array['xhttp-opts']['sc-max-concurrent-posts'] = $xhttpSettings['extra']['xmux']['maxConcurrency'] ?? [];
                    //}

                //}
            }
        }

        if (isset($server['encryption']) && !empty($server['encryption']) && isset($server['encryption_settings']) && !empty($server['encryption_settings'])) {
            $encryptionSettings = $server['encryption_settings'];
            $array['encryption'] = $server['encryption'] ?? 'mlkem768x25519plus';
            $array['encryption'] .= '.' . $encryptionSettings['mode'] ?? 'native';
            $array['encryption'] .= '.' . $encryptionSettings['rtt'] ?? '1rtt';
            if (isset($encryptionSettings['client_padding']) && !empty($encryptionSettings['client_padding'])) {
                $array['encryption'] .= '.' . $encryptionSettings['client_padding'];
            }
            $array['encryption'] .= '.' . $encryptionSettings['password'] ?? '';
        }

        return $array;
    }

    public static function buildTrojan($password, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['password'] = $password;
        $array['udp'] = true;
        if(isset($server['network']) && in_array($server['network'], ["grpc", "ws"])){
            $array['network'] = $server['network'];
            // grpc配置
            if($server['network'] === "grpc" && isset($server['network_settings']['serviceName'])) {
                $array['grpc-opts']['grpc-service-name'] = $server['network_settings']['serviceName'];
            }
            // ws配置
            if($server['network'] === "ws") {
                if(isset($server['network_settings']['path'])) {
                    $array['ws-opts']['path'] = $server['network_settings']['path'];
                }
                if(isset($server['network_settings']['headers']['Host'])){
                    $array['ws-opts']['headers']['Host'] = $server['network_settings']['headers']['Host'];
                }
            }
        };
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $array['skip-cert-verify'] = Helper::shouldSkipCertVerify($server, $tlsSettings);
        if (!empty($tlsSettings['ech'])) {
            if ($tlsSettings['ech'] === 'cloudflare') {
                $array['ech-opts'] = [
                    'enable' => true,
                    'query-server-name' => 'cloudflare-ech.com'
                ];
            } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                $array['ech-opts'] = [
                    'enable' => true,
                    'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']]
                ];
            }
        }
        $array = Helper::appendCertificateFingerprint($array, $tlsSettings);
        return $array;
    }

    public static function buildTuic($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $password,
            'password' => $password,
            'alpn' => ['h3'],
            'disable-sni' => $server['disable_sni'] ? true : false,
            'reduce-rtt' => $server['zero_rtt_handshake'] ? true : false,
            'udp-relay-mode' => $server['udp_relay_mode'] ?? 'native',
            'congestion-controller' => $server['congestion_control'] ?? 'cubic',
        ];
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['skip-cert-verify'] = Helper::shouldSkipCertVerify($server, $tlsSettings);
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $array = Helper::appendCertificateFingerprint($array, $tlsSettings);

        return $array;
    }

    public static function buildAnyTLS($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'client-fingerprint' => 'chrome',
            'udp' => true,
            'alpn' => [
                'http/1.1',
            ],
        ];
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $array['skip-cert-verify'] = Helper::shouldSkipCertVerify($server, $tlsSettings);
        $array = Helper::appendCertificateFingerprint($array, $tlsSettings);
        return $array;
    }

    public static function buildHysteria($password, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['server'] = $server['host'];

        $parts = explode(",", $server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $array['port'] = (int)$firstPort;
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }
        $array['udp'] = true;
        $array['skip-cert-verify'] = $server['insecure'] == 1 ? true : false;

        if (isset($server['server_name'])) $array['sni'] = $server['server_name'];

        if ($server['version'] === 2) {
            $array['type'] = 'hysteria2';
            $array['password'] = $password;
            if (isset($server['obfs'])){
                $array['obfs'] = $server['obfs'];
                $array['obfs-password'] = $server['obfs_password'];
            }
        } else {
            $array['type'] = 'hysteria';
            $array['auth_str'] = $password;
            if (isset($server['obfs']) && isset($server['obfs_password'])){
                $array['obfs'] = $server['obfs_password'];
            }
            //Todo:完善客户端上下行
            $array['up'] = $server['down_mbps'];
            $array['down'] = $server['up_mbps'];
            $array['protocol'] = 'udp';
        }

        return $array;
    }

    private function buildHysteria2($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $sni = $tlsSettings['server_name'] ?? ($server['server_name'] ?? 'genshin.hoyoverse.com');
        $array = [
            'name' => $server['name'],
            'type' => 'hysteria2',
            'server' => $server['host'],
            'password' => $password,
            'skip-cert-verify' => Helper::shouldSkipCertVerify($server, $tlsSettings),
            'sni' => $sni,
            'udp' => true,
        ];
        $array = Helper::appendCertificateFingerprint($array, $tlsSettings);
        $parts = explode(",", $server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $array['port'] = (int)$firstPort;
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }
        if (isset($server['obfs'])){
            $array['obfs'] = $server['obfs'];
            $array['obfs-password'] = $server['obfs_password'];
        }
        return $array;
    }

    protected function resolveProxyGroups(array $groups, array $proxies): array
    {
        $resolvedGroups = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $sources = is_array($group['proxies'] ?? null) ? $group['proxies'] : [];
            $resolvedProxies = [];
            $hasRegexFilter = false;
            foreach ($sources as $source) {
                if (!$this->isRegex($source)) {
                    $resolvedProxies[] = $source;
                    continue;
                }

                $hasRegexFilter = true;
                foreach ($proxies as $proxyName) {
                    if ($this->isMatch($source, $proxyName)) {
                        $resolvedProxies[] = $proxyName;
                    }
                }
            }

            if (!$hasRegexFilter && empty($group['__skip_auto_fill'])) {
                $resolvedProxies = array_merge($resolvedProxies, $proxies);
            }

            unset($group['__skip_auto_fill']);
            $group['proxies'] = array_values(array_unique($resolvedProxies));
            if ($group['proxies']) {
                $resolvedGroups[] = $group;
            }
        }

        return $resolvedGroups;
    }

    private function isMatch($exp, $str)
    {
        return @preg_match($exp, $str);
    }

    private function isRegex($exp)
    {
        return @preg_match($exp, '') !== false;
    }

}
