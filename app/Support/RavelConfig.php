<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class RavelConfig
{
    public const DEFAULTS = [
        'stream_window' => 1048576,
        'connection_window' => 4194304,
        'max_streams' => 256,
        'max_message' => 1048576,
        'max_frame_receive' => 65536,
        'claim_capacity' => 65536,
        'chunk_min' => 1024,
        'chunk_max' => 65536,
        'chunk_min_delay' => 2,
        'chunk_max_delay' => 8,
        'handshake_timeout' => 10,
        'idle_timeout' => 300,
        'connection_lifetime' => 3600,
        'claim_retention' => 7200,
        'max_sessions' => 2,
        'session_max_streams' => 1024,
        'session_max_bytes' => 1073741824,
        'drain_timeout' => 30,
    ];

    public static function rules(string $protocolField = 'protocol'): array
    {
        return [
            'route_id' => "nullable|array|prohibited_if:{$protocolField},ravel",
            'ravel_authority' => [
                'nullable',
                "required_if:{$protocolField},ravel",
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (is_string($value) && !self::isValidAuthority($value)) {
                        $fail($attribute . ' 不是有效的 HTTP authority');
                    }
                },
            ],
            'ravel_path' => "nullable|required_if:{$protocolField},ravel|string|max:2048|regex:/\A\/[^\r\n]*\z/",
            'ravel_gateway_group' => "nullable|required_if:{$protocolField},ravel|string|regex:/\A[\x21-\x7E]{8}\z/",
            'ravel_masquerade' => [
                'nullable',
                "required_if:{$protocolField},ravel",
                'string',
                'max:2048',
                function ($attribute, $value, $fail) {
                    if (is_string($value) && !self::isValidMasqueradeUrl($value)) {
                        $fail($attribute . ' 必须是 file:///绝对路径、http://URL 或 https://URL');
                    }
                },
            ],
            'ravel_settings' => 'nullable|array',
            'ravel_settings.stream_window' => 'nullable|integer|min:65536|max:16777216',
            'ravel_settings.connection_window' => 'nullable|integer|min:65536|max:67108864',
            'ravel_settings.max_streams' => 'nullable|integer|min:1|max:4096',
            'ravel_settings.max_message' => 'nullable|integer|min:4096|max:4194304',
            'ravel_settings.max_frame_receive' => 'nullable|integer|min:9|max:1048576',
            'ravel_settings.claim_capacity' => 'nullable|integer|min:1|max:1048576',
            'ravel_settings.chunk_min' => 'nullable|integer|min:256|max:1048576',
            'ravel_settings.chunk_max' => 'nullable|integer|min:256|max:1048576',
            'ravel_settings.chunk_min_delay' => 'nullable|integer|min:1|max:1000',
            'ravel_settings.chunk_max_delay' => 'nullable|integer|min:1|max:1000',
            'ravel_settings.handshake_timeout' => 'nullable|integer|min:1|max:60',
            'ravel_settings.idle_timeout' => 'nullable|integer|min:5|max:86400',
            'ravel_settings.connection_lifetime' => 'nullable|integer|min:60|max:86400',
            'ravel_settings.claim_retention' => 'nullable|integer|min:60|max:604800',
            'ravel_settings.max_sessions' => 'nullable|integer|min:1|max:16',
            'ravel_settings.session_max_streams' => 'nullable|integer|min:1|max:1048576',
            'ravel_settings.session_max_bytes' => 'nullable|integer|min:65536|max:1099511627776',
            'ravel_settings.drain_timeout' => 'nullable|integer|min:1|max:300',
        ];
    }

    public static function normalize(string $protocol, ?array $settings = null): array
    {
        if ($protocol !== 'ravel') {
            return $settings ?? [];
        }

        $settings = array_merge(
            self::DEFAULTS,
            array_intersect_key($settings ?? [], self::DEFAULTS)
        );
        self::validateRelationships($settings);

        return array_map('intval', $settings);
    }

    public static function validateRelationships(array $settings): void
    {
        $settings = array_merge(self::DEFAULTS, $settings);
        $errors = [];

        if ((int) $settings['chunk_max'] > (int) $settings['max_message']) {
            $errors['ravel_settings.chunk_max'] = ['chunk_max 不能大于 max_message'];
        }
        if ((int) $settings['chunk_min'] > (int) $settings['chunk_max']) {
            $errors['ravel_settings.chunk_min'] = ['chunk_min 不能大于 chunk_max'];
        }
        if ((int) $settings['chunk_min_delay'] > (int) $settings['chunk_max_delay']) {
            $errors['ravel_settings.chunk_min_delay'] = [
                'chunk_min_delay 不能大于 chunk_max_delay',
            ];
        }
        if ((int) $settings['stream_window'] > (int) $settings['connection_window']) {
            $errors['ravel_settings.stream_window'] = [
                'stream_window 不能大于 connection_window',
            ];
        }
        if ((int) $settings['connection_lifetime'] > (int) $settings['claim_retention']) {
            $errors['ravel_settings.connection_lifetime'] = [
                'connection_lifetime 不能大于 claim_retention',
            ];
        }
        if ((int) $settings['drain_timeout'] >= (int) $settings['connection_lifetime']) {
            $errors['ravel_settings.drain_timeout'] = ['drain_timeout 必须小于 connection_lifetime'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function nodePayload($server): array
    {
        return [
            'authority' => (string) $server->ravel_authority,
            'path' => (string) $server->ravel_path,
            'gateway_group' => (string) $server->ravel_gateway_group,
            'masquerade' => (string) $server->ravel_masquerade,
            'settings' => self::normalize('ravel', $server->ravel_settings ?: []),
        ];
    }

    private static function isValidMasqueradeUrl(string $value): bool
    {
        if (
            $value === ''
            || preg_match('/[\x00-\x20\x7F\\\\]/', $value)
        ) {
            return false;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'file') {
            if (strpos(strtolower($value), 'file:///') !== 0) {
                return false;
            }
            if (
                isset($parts['host'])
                || isset($parts['port'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                return false;
            }
            $path = rawurldecode((string) ($parts['path'] ?? ''));
            return isset($path[0])
                && $path[0] === '/'
                && strpos($path, "\0") === false;
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if (
            empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return false;
            }
        }

        return true;
    }

    private static function isValidAuthority(string $value): bool
    {
        if (
            $value === ''
            || trim($value) !== $value
            || preg_match('/[\x00-\x20\x7F\/\\\\@]/', $value)
        ) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        if (preg_match('/\A\[([0-9A-Fa-f:.]+)\](?::([0-9]+))?\z/', $value, $match)) {
            if (!filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return false;
            }
            return !isset($match[2]) || self::isValidPort($match[2]);
        }
        if (preg_match('/\A([A-Za-z0-9._-]+)(?::([0-9]+))?\z/', $value, $match)) {
            return !isset($match[2]) || self::isValidPort($match[2]);
        }

        return false;
    }

    private static function isValidPort(string $value): bool
    {
        if ($value === '' || !ctype_digit($value)) {
            return false;
        }
        $port = (int) $value;
        return $port >= 1 && $port <= 65535;
    }
}
