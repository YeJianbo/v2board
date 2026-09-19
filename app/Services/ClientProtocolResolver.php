<?php

namespace App\Services;

class ClientProtocolResolver
{
    private const PROTOCOLS = [
        \App\Protocols\v2RayTun::class,
        \App\Protocols\V2rayNG::class,
        \App\Protocols\V2rayN::class,
        \App\Protocols\Surge::class,
        \App\Protocols\Surfboard::class,
        \App\Protocols\Stash::class,
        \App\Protocols\SSRPlus::class,
        \App\Protocols\Shadowsocks::class,
        \App\Protocols\Shadowrocket::class,
        \App\Protocols\SagerNet::class,
        \App\Protocols\QuantumultX::class,
        \App\Protocols\Passwall::class,
        \App\Protocols\Loon::class,
        \App\Protocols\General::class,
        \App\Protocols\ClashVerge::class,
        \App\Protocols\ClashNyanpasu::class,
        \App\Protocols\ClashMeta::class,
        \App\Protocols\Clash::class,
    ];

    private const FLAGS = [
        \App\Protocols\v2RayTun::class => 'v2raytun',
        \App\Protocols\V2rayNG::class => 'v2rayng',
        \App\Protocols\V2rayN::class => 'v2rayn',
        \App\Protocols\Surge::class => 'surge',
        \App\Protocols\Surfboard::class => 'surfboard',
        \App\Protocols\Stash::class => 'stash',
        \App\Protocols\SSRPlus::class => 'ssrplus',
        \App\Protocols\Shadowsocks::class => 'shadowsocks',
        \App\Protocols\Shadowrocket::class => 'shadowrocket',
        \App\Protocols\SagerNet::class => 'sagernet',
        \App\Protocols\QuantumultX::class => 'quantumult%20x',
        \App\Protocols\Passwall::class => 'passwall',
        \App\Protocols\Loon::class => 'loon',
        \App\Protocols\General::class => 'general',
        \App\Protocols\ClashVerge::class => 'verge',
        \App\Protocols\ClashNyanpasu::class => 'nyanpasu',
        \App\Protocols\ClashMeta::class => 'meta',
        \App\Protocols\Clash::class => 'clash',
    ];

    public function resolve(string $flag): ?string
    {
        $flag = strtolower($flag);
        foreach (self::PROTOCOLS as $protocolClass) {
            if (strpos($flag, self::FLAGS[$protocolClass]) !== false) {
                return $protocolClass;
            }
        }

        return null;
    }

    public function isSingbox(string $flag): bool
    {
        $flag = strtolower($flag);
        return strpos($flag, 'sing') !== false
            || strpos($flag, 'neko') !== false
            || strpos($flag, 'nb4a') !== false;
    }

    public function singboxVersion(string $flag): ?string
    {
        if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
