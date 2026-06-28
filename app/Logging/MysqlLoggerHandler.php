<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    private const MASK = '[FILTERED]';

    private const SENSITIVE_KEYS = [
        'auth_data',
        'authorization',
        'access_token',
        'refresh_token',
        'user_token',
        'token',
        'password',
        'passwd',
        'pwd',
        'secret',
        'api_key',
        'apikey',
        'api-key',
        'key',
        'private_key',
        'public_key',
        'obfs_password',
        'communication_key',
        'communication_secret',
        'machine_key',
        'machine_secret',
        'cfkey',
        'cf_key',
    ];

    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try{
            if(isset($record['context']['exception']) && is_object($record['context']['exception'])){
                $record['context']['exception'] = (array)$record['context']['exception'];
            }
            $request = null;
            if (app()->bound('request')) {
                try {
                    $request = app('request');
                } catch (\Throwable $e) {
                    $request = null;
                }
            }
            $record['request_data'] = $request ? $this->sanitizeData($request->all() ?? []) : [];
            if (isset($record['context'])) {
                $record['context'] = $this->sanitizeData($record['context']);
            }
            $datetime = $record['datetime'] ?? null;
            $timestamp = $datetime instanceof \DateTimeInterface
                ? $datetime->getTimestamp()
                : strtotime((string)$datetime);
            if (!$timestamp) {
                $timestamp = time();
            }
            $log = [
                'title' => $record['message'],
                'level' => $record['level_name'],
                'host' => $record['request_host'] ?? ($request ? $request->getSchemeAndHttpHost() : ''),
                'uri' => $this->sanitizeUri($record['request_uri'] ?? ($request ? $request->getRequestUri() : 'console')),
                'method' => $record['request_method'] ?? ($request ? $request->getMethod() : 'CLI'),
                'ip' => $request ? $request->getClientIp() : '',
                'data' => json_encode($record['request_data']) ,
                'context' => isset($record['context']) ? json_encode($record['context']) : '',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            LogModel::insert(
                $log
            );
        }catch (\Throwable $e){
            Log::channel('daily')->error($e->getMessage().$e->getFile().$e->getTraceAsString());
        }
    }

    private function sanitizeData($value, string $key = '')
    {
        if ($this->isSensitiveKey($key)) {
            return self::MASK;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeData($childValue, (string)$childKey);
            }
            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitizeData((array)$value, $key);
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/i', 'Bearer ' . self::MASK, $value);
        $value = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', self::MASK, $value);

        return $value;
    }

    private function sanitizeUri(string $uri): string
    {
        if ($uri === '' || strpos($uri, '?') === false) {
            return $this->sanitizeString($uri);
        }

        [$path, $query] = explode('?', $uri, 2);
        $fragment = '';
        if (strpos($query, '#') !== false) {
            [$query, $fragment] = explode('#', $query, 2);
            $fragment = '#' . $fragment;
        }

        parse_str($query, $params);
        $params = $this->sanitizeData($params);
        $safeQuery = http_build_query($params);

        return $this->sanitizeString($path . ($safeQuery ? '?' . $safeQuery : '') . $fragment);
    }

    private function isSensitiveKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return preg_match('/(^|_)(password|passwd|pwd|token|secret|authorization|auth_data|api_?key|private_?key|obfs_?password|cf_?key)(_|$)/', $normalized) === 1;
    }
}
