<?php

namespace App\Support;

class RavelSubscription
{
    public static function serverName(array $server): string
    {
        foreach ([$server['tls_settings']['server_name'] ?? '', $server['server_name'] ?? ''] as $name) {
            if (is_string($name) && trim($name) !== '') { return trim($name); }
        }
        $authority = (string) ($server['ravel_authority'] ?? '');
        if (filter_var($authority, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { return $authority; }
        return trim((string) parse_url('https://' . $authority, PHP_URL_HOST), '[]');
    }
    public static function latestCredential(array $server): ?array
    {
        $credentials = array_values(array_filter(
            $server['ravel_credentials'] ?? [],
            function ($credential) {
                return is_array($credential)
                    && !empty($credential['credential_id'])
                    && !empty($credential['capability_key']);
            }
        ));

        usort($credentials, function ($left, $right) {
            return (int) ($right['key_version'] ?? 0)
                <=> (int) ($left['key_version'] ?? 0);
        });

        return $credentials[0] ?? null;
    }

    public static function mihomo(array $server): ?array
    {
        $credential = self::latestCredential($server);
        if (!$credential) {
            return null;
        }

        $tlsSettings = $server['tls_settings'] ?? [];
        $payload = [
            'name' => (string) $server['name'],
            'type' => 'ravel',
            'server' => (string) $server['host'],
            'port' => (int) $server['port'],
            'sni' => self::serverName($server),
            'authority' => (string) $server['ravel_authority'],
            'path' => (string) $server['ravel_path'],
            'skip-cert-verify' => (bool) ($tlsSettings['allow_insecure'] ?? false),
            'credential-id' => (string) $credential['credential_id'],
            'capability-key' => (string) $credential['capability_key'],
            'key-version' => (int) $credential['key_version'],
            'gateway-group' => (string) $credential['gateway_group'],
            'not-before' => (int) $credential['not_before'],
            'not-after' => (int) $credential['not_after'],
        ];

        if ((int) ($credential['policy_id'] ?? 0) !== 0) {
            $payload['policy-id'] = (int) $credential['policy_id'];
        }

        return array_merge($payload, self::clientSettings($server, '-'));
    }

    public static function singbox(array $server): ?array
    {
        $credential = self::latestCredential($server);
        if (!$credential) {
            return null;
        }

        $tlsSettings = $server['tls_settings'] ?? [];

        return array_merge([
            'type' => 'ravel',
            'tag' => (string) $server['name'],
            'server' => (string) $server['host'],
            'server_port' => (int) $server['port'],
            'tls' => [
                'enabled' => true,
                'insecure' => (bool) ($tlsSettings['allow_insecure'] ?? false),
                'server_name' => self::serverName($server),
            ],
            'authority' => (string) $server['ravel_authority'],
            'path' => (string) $server['ravel_path'],
            'credential_id' => (string) $credential['credential_id'],
            'capability_key' => (string) $credential['capability_key'],
            'key_version' => (int) $credential['key_version'],
            'policy_id' => 0,
            'gateway_group' => (string) $credential['gateway_group'],
            'not_before' => (int) $credential['not_before'],
            'not_after' => (int) $credential['not_after'],
        ], self::clientSettings($server, '_'));
    }

    private static function clientSettings(array $server, string $separator): array
    {
        $settings = RavelConfig::normalize('ravel', $server['ravel_settings'] ?? []);
        $map = [
            'max_message' => 'max_message_size',
            'max_frame_receive' => 'max_frame_receive',
            'connection_window' => 'initial_connection_window',
            'stream_window' => 'initial_stream_window',
            'handshake_timeout' => 'handshake_timeout_seconds',
            'chunk_min' => 'chunk_min_size',
            'chunk_max' => 'chunk_max_size',
            'chunk_min_delay' => 'chunk_min_delay_ms',
            'chunk_max_delay' => 'chunk_max_delay_ms',
            'max_sessions' => 'max_sessions',
            'session_max_streams' => 'session_max_streams',
            'session_max_bytes' => 'session_max_bytes',
            'drain_timeout' => 'drain_timeout_seconds',
        ];
        $result = [];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $settings)) {
                $result[str_replace('_', $separator, $target)] = $settings[$source];
            }
        }
        return $result;
    }
}
