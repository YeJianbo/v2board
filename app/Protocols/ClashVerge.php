<?php

namespace App\Protocols;

use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

class ClashVerge
{
    private const HEALTHCHECK_URL = 'http://cp.cloudflare.com/generate_204';
    private const GLOBAL_AUTO_NAME = '♻️ 自动选择';
    private const REGION_UNKNOWN = 'OTHER';
    private const REGION_META = [
        'CN' => ['flag' => '🇨🇳', 'label' => '中国'],
        'HK' => ['flag' => '🇭🇰', 'label' => '香港'],
        'MO' => ['flag' => '🇲🇴', 'label' => '澳门'],
        'TW' => ['flag' => '🇹🇼', 'label' => '台湾'],
        'JP' => ['flag' => '🇯🇵', 'label' => '日本'],
        'KR' => ['flag' => '🇰🇷', 'label' => '韩国'],
        'SG' => ['flag' => '🇸🇬', 'label' => '新加坡'],
        'MY' => ['flag' => '🇲🇾', 'label' => '马来西亚'],
        'TH' => ['flag' => '🇹🇭', 'label' => '泰国'],
        'PH' => ['flag' => '🇵🇭', 'label' => '菲律宾'],
        'US' => ['flag' => '🇺🇸', 'label' => '美国'],
        'CA' => ['flag' => '🇨🇦', 'label' => '加拿大'],
        'GB' => ['flag' => '🇬🇧', 'label' => '英国'],
        'DE' => ['flag' => '🇩🇪', 'label' => '德国'],
        'FR' => ['flag' => '🇫🇷', 'label' => '法国'],
        'NL' => ['flag' => '🇳🇱', 'label' => '荷兰'],
        'AU' => ['flag' => '🇦🇺', 'label' => '澳大利亚'],
        self::REGION_UNKNOWN => ['flag' => '🌐', 'label' => '其他地区'],
    ];
    private const REGION_TOKEN_MAP = [
        '中国' => 'CN',
        '大陆' => 'CN',
        '内地' => 'CN',
        '香港' => 'HK',
        'hk' => 'HK',
        'hong kong' => 'HK',
        'hongkong' => 'HK',
        '澳门' => 'MO',
        'macao' => 'MO',
        'macau' => 'MO',
        '台湾' => 'TW',
        'tw' => 'TW',
        'taiwan' => 'TW',
        '日本' => 'JP',
        'jp' => 'JP',
        'japan' => 'JP',
        '韩国' => 'KR',
        'kr' => 'KR',
        'korea' => 'KR',
        '新加坡' => 'SG',
        'sg' => 'SG',
        'singapore' => 'SG',
        '马来西亚' => 'MY',
        '大马' => 'MY',
        'my' => 'MY',
        'malaysia' => 'MY',
        '泰国' => 'TH',
        'th' => 'TH',
        'thailand' => 'TH',
        '菲律宾' => 'PH',
        'ph' => 'PH',
        'philippines' => 'PH',
        '美国' => 'US',
        '美國' => 'US',
        'us' => 'US',
        'usa' => 'US',
        'america' => 'US',
        'united states' => 'US',
        '加拿大' => 'CA',
        'ca' => 'CA',
        'canada' => 'CA',
        '英国' => 'GB',
        '英國' => 'GB',
        'uk' => 'GB',
        'gb' => 'GB',
        'britain' => 'GB',
        'united kingdom' => 'GB',
        '德国' => 'DE',
        '德國' => 'DE',
        'de' => 'DE',
        'germany' => 'DE',
        '法国' => 'FR',
        '法國' => 'FR',
        'fr' => 'FR',
        'france' => 'FR',
        '荷兰' => 'NL',
        '荷蘭' => 'NL',
        'nl' => 'NL',
        'netherlands' => 'NL',
        '澳大利亚' => 'AU',
        '澳洲' => 'AU',
        'au' => 'AU',
        'australia' => 'AU',
    ];
    public $flag = 'verge';
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
        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header('profile-title: base64:' . base64_encode($appName));
        if ($appUrl) {
            header('profile-web-page-url: ' . $appUrl);
            header('support-url: ' . $appUrl);
        }
        header("content-disposition:attachment;filename*=UTF-8''".rawurlencode($appName));
        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom2.clash.yaml';
        if (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];
        $regionProxyMap = [];

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
            }

            if ($handled && !empty($item['name'])) {
                $regionCode = $this->inferRegionCode($item);
                if (!isset($regionProxyMap[$regionCode])) {
                    $regionProxyMap[$regionCode] = [];
                }
                $regionProxyMap[$regionCode][] = $item['name'];
            }
        }

        $config['proxy-groups'] = $this->injectDynamicRegionGroups(
            $config['proxy-groups'] ?? [],
            $regionProxyMap
        );
        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies'])) $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src)) continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter) continue;
            }
            if ($isFilter) continue;
            if (!empty($config['proxy-groups'][$k]['__skip_auto_fill'])) continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function($group) {
            unset($group['__skip_auto_fill']);
            return $group['proxies'];
        });
        foreach ($config['proxy-groups'] as &$group) {
            unset($group['__skip_auto_fill']);
            $group['proxies'] = array_values(array_unique($group['proxies']));
        }
        unset($group);
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        // Force the current subscription domain to be a direct rule
        //$subsDomain = $_SERVER['HTTP_HOST'];
        //if ($subsDomain) {
        //    array_unshift($config['rules'], "DOMAIN,{$subsDomain},DIRECT");
        //}

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return $yaml;
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
                'h2',
                'http/1.1',
            ],
        ];
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $array['skip-cert-verify'] = Helper::shouldSkipCertVerify($server, $tlsSettings);
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

    private function isMatch($exp, $str)
    {
        return @preg_match($exp, $str);
    }

    private function isRegex($exp)
    {
        return @preg_match($exp, '') !== false;
    }

    private function injectDynamicRegionGroups(array $groups, array $regionProxyMap): array
    {
        $builtGroups = $this->buildRegionGroups($regionProxyMap);
        if (empty($builtGroups['groups'])) {
            return $groups;
        }
        return array_merge($groups, $builtGroups['groups']);
    }

    private function buildRegionGroups(array $regionProxyMap): array
    {
        if (empty($regionProxyMap)) {
            return ['groups' => [], 'selectorNames' => []];
        }

        $groups = [];
        foreach ($this->sortRegionProxyMap($regionProxyMap) as $regionCode => $proxyNames) {
            $proxyNames = array_values(array_unique(array_filter($proxyNames)));
            if (empty($proxyNames)) {
                continue;
            }

            $regionMeta = self::REGION_META[$regionCode] ?? self::REGION_META[self::REGION_UNKNOWN];
            $regionLabel = $regionMeta['label'];
            $regionSelectorName = '📍 地区-' . $regionMeta['flag'] . ' ' . $regionLabel;
            $autoGroupName = '♻️ 地区自动-' . $regionLabel;
            $fallbackGroupName = '🔯 地区故障转移-' . $regionLabel;

            $groups[] = [
                'name' => $autoGroupName,
                'type' => 'url-test',
                'proxies' => $proxyNames,
                'url' => self::HEALTHCHECK_URL,
                'interval' => 300,
                '__skip_auto_fill' => true,
            ];
            $groups[] = [
                'name' => $fallbackGroupName,
                'type' => 'fallback',
                'proxies' => $proxyNames,
                'url' => self::HEALTHCHECK_URL,
                'interval' => 300,
                '__skip_auto_fill' => true,
            ];
            $groups[] = [
                'name' => $regionSelectorName,
                'type' => 'select',
                'proxies' => array_merge([$autoGroupName, $fallbackGroupName], $proxyNames),
                '__skip_auto_fill' => true,
            ];
        }

        return ['groups' => $groups, 'selectorNames' => []];
    }

    private function sortRegionProxyMap(array $regionProxyMap): array
    {
        $orderedCodes = array_keys(self::REGION_META);
        $sorted = [];

        foreach ($orderedCodes as $regionCode) {
            if (isset($regionProxyMap[$regionCode])) {
                $sorted[$regionCode] = $regionProxyMap[$regionCode];
            }
        }

        foreach ($regionProxyMap as $regionCode => $proxyNames) {
            if (!isset($sorted[$regionCode])) {
                $sorted[$regionCode] = $proxyNames;
            }
        }

        return $sorted;
    }

    private function inferRegionCode(array $server): string
    {
        $sources = [
            $server['country_code'] ?? null,
            $server['countryCode'] ?? null,
            $server['country'] ?? null,
            $server['country_name'] ?? null,
            $server['region'] ?? null,
            $server['area'] ?? null,
            $server['location'] ?? null,
            $server['name'] ?? null,
            $server['host'] ?? null,
        ];

        foreach ($sources as $source) {
            $regionCode = $this->inferRegionCodeFromSource($source);
            if ($regionCode !== null) {
                return $regionCode;
            }
        }

        return self::REGION_UNKNOWN;
    }

    private function inferRegionCodeFromSource($source): ?string
    {
        $value = trim((string) $source);
        if ($value === '') {
            return null;
        }

        $upperValue = strtoupper($value);
        if (isset(self::REGION_META[$upperValue])) {
            return $upperValue;
        }
        if ($upperValue === 'UK') {
            return 'GB';
        }

        $flagMap = [
            '🇨🇳' => 'CN',
            '🇭🇰' => 'HK',
            '🇲🇴' => 'MO',
            '🇹🇼' => 'TW',
            '🇯🇵' => 'JP',
            '🇰🇷' => 'KR',
            '🇸🇬' => 'SG',
            '🇲🇾' => 'MY',
            '🇹🇭' => 'TH',
            '🇵🇭' => 'PH',
            '🇺🇸' => 'US',
            '🇨🇦' => 'CA',
            '🇬🇧' => 'GB',
            '🇩🇪' => 'DE',
            '🇫🇷' => 'FR',
            '🇳🇱' => 'NL',
            '🇦🇺' => 'AU',
        ];

        foreach ($flagMap as $flag => $regionCode) {
            if (mb_strpos($value, $flag) !== false) {
                return $regionCode;
            }
        }

        $normalizedValue = mb_strtolower($value);
        foreach (self::REGION_TOKEN_MAP as $token => $regionCode) {
            if (mb_strpos($normalizedValue, mb_strtolower($token)) !== false) {
                return $regionCode;
            }
        }

        if (preg_match('/\b([A-Za-z]{2})\b/u', $value, $matches)) {
            $token = strtoupper($matches[1]);
            if ($token === 'UK') {
                return 'GB';
            }
            if (isset(self::REGION_META[$token])) {
                return $token;
            }
        }

        return null;
    }
}
