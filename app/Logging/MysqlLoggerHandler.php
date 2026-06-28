<?php
namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    private const MASK = '[FILTERED]';
    private const TEXT_LIMIT = 16000;
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 100;
    private const FAILURE_COOLDOWN_SECONDS = 60;

    private static int $disabledUntil = 0;

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
        if (self::$disabledUntil > time()) {
            return;
        }

        try{
            if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
                $record['context']['exception'] = $this->sanitizeThrowable($record['context']['exception']);
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
            self::$disabledUntil = time() + self::FAILURE_COOLDOWN_SECONDS;
            error_log(sprintf(
                'MysqlLoggerHandler disabled for %d seconds after failure: %s in %s:%d',
                self::FAILURE_COOLDOWN_SECONDS,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }
    }

    private function sanitizeData($value, string $key = '', int $depth = 0)
    {
        if ($this->isSensitiveKey($key)) {
            return self::MASK;
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[DEPTH_LIMIT]';
        }

        if ($value instanceof \Throwable) {
            return $this->sanitizeThrowable($value);
        }

        if (is_array($value)) {
            $sanitized = [];
            $count = 0;
            foreach ($value as $childKey => $childValue) {
                if ($count >= self::MAX_ITEMS) {
                    $sanitized['__truncated'] = true;
                    break;
                }
                $sanitized[$childKey] = $this->sanitizeData($childValue, (string)$childKey, $depth + 1);
                $count++;
            }
            return $sanitized;
        }

        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            return [
                '__class' => get_class($value),
                '__summary' => '[OBJECT]',
            ];
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function sanitizeThrowable(\Throwable $throwable): array
    {
        return [
            'class' => get_class($throwable),
            'message' => $this->limitString($this->sanitizeString($throwable->getMessage()), 2000),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'code' => $throwable->getCode(),
            'trace' => array_slice(array_map(function ($frame) {
                return [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                    'class' => $frame['class'] ?? null,
                ];
            }, $throwable->getTrace()), 0, 20),
        ];
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
