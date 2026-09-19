<?php

namespace App\Protocols;

use App\Support\RavelSubscription;
use App\Support\RavelConfig;

class Ravel
{
    public $flag = 'ravel-json-v1';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $nodes = [];
        foreach ($this->servers as $server) {
            if (
                (string) ($server['type'] ?? '') !== 'v2node'
                || (string) ($server['protocol'] ?? '') !== 'ravel'
            ) {
                continue;
            }

            $credential = RavelSubscription::latestCredential($server);
            if (!$credential) {
                continue;
            }

            $tlsSettings = $server['tls_settings'] ?? [];
            $nodes[] = [
                'name' => (string) $server['name'],
                'type' => 'ravel',
                'server' => (string) $server['host'],
                'port' => (int) $server['port'],
                'sni' => RavelSubscription::serverName($server),
                'authority' => (string) $server['ravel_authority'],
                'path' => (string) $server['ravel_path'],
                'insecure' => (bool) ($tlsSettings['allow_insecure'] ?? false),
                'gateway_group' => (string) $server['ravel_gateway_group'],
                'masquerade' => (string) ($server['ravel_masquerade'] ?? ''),
                'settings' => RavelConfig::normalize('ravel', $server['ravel_settings'] ?? []),
                'credentials' => [$credential],
            ];
        }

        return response()->json([
            'schema' => 'v2board.ravel.subscription',
            'schema_version' => 1,
            'generated_at' => time(),
            'nodes' => $nodes,
        ])->header('Cache-Control', 'private, no-store');
    }
}
