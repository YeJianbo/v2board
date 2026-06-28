<?php
namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    private const MASK = '[FILTERED]';
    private const TEXT_LIMIT = 16000;

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
                'title' => $this->limitString((string)($record['message'] ?? ''), self::TEXT_LIMIT),
                'level' => $this->limitString((string)($record['level_name'] ?? ''), 11),
                'host' => $this->limitString((string)($record['request_host'] ?? ($request ? $request->getSchemeAndHttpHost() : '')), 255),
                'uri' => $this->limitString($this->sanitizeUri($record['request_uri'] ?? ($request ? $request->getRequestUri() : 'console')), 255),
                'method' => $this->limitString((string)($record['request_method'] ?? ($request ? $request->getMethod() : 'CLI')), 11),
                'ip' => $this->limitString((string)($request ? $request->getClientIp() : ''), 128),
                'data' => $this->safeJson($record['request_data']),
                'context' => isset($record['context']) ? $this->safeJson($record['context']) : '',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            LogModel::insert(
                $log
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'MysqlLoggerHandler failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
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

    private function safeJson($value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if (!is_string($json)) {
            $json = '';
        }

        return $this->limitString($json, self::TEXT_LIMIT);
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/i', 'Bearer ' . self::MASK, $value) ?? $value;
        $value = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', self::MASK, $value) ?? $value;

        return $value;
    }

    private function limitString(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $limit
                ? mb_substr($value, 0, $limit, 'UTF-8')
                : $value;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
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
