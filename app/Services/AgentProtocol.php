<?php

namespace App\Services;

class AgentProtocol
{
    public const TYPES = ['openai', 'responses', 'anthropic', 'gemini'];

    public static function request(string $protocol, array $config, array $body): array
    {
        abort_unless(in_array($protocol, self::TYPES, true), 422, '不支持的模型协议');
        $base = rtrim($config['base_url'], '/');
        $key = $config['api_key'];
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
        $messages = $body['messages']; $tools = $body['tools'] ?? [];
        if ($protocol === 'responses') {
            $input = [];
            foreach ($messages as $m) {
                if ($m['role'] === 'tool') $input[] = ['type' => 'function_call_output', 'call_id' => $m['tool_call_id'], 'output' => $m['content']];
                elseif (isset($m['_provider']['responses'])) $input = array_merge($input, $m['_provider']['responses']);
                else {
                    if (!empty($m['content'])) $input[] = ['role' => $m['role'], 'content' => $m['content']];
                    foreach ($m['tool_calls'] ?? [] as $call) $input[] = ['type' => 'function_call', 'call_id' => $call['id'], 'name' => $call['function']['name'], 'arguments' => $call['function']['arguments']];
                }
            }
            $payload = ['model' => $body['model'], 'input' => $input, 'max_output_tokens' => $body['max_tokens'], 'stream' => true, 'store' => false, 'include' => ['reasoning.encrypted_content']];
            if ($tools) $payload['tools'] = array_map(fn ($t) => ['type' => 'function'] + $t['function'], $tools);
            return [$base . '/responses', array_merge($headers, ['Authorization: Bearer ' . $key]), $payload];
        }
        $system = []; $history = []; $names = []; $nativeIds = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $system[] = $m['content']; continue; }
            foreach ($m['tool_calls'] ?? [] as $c) $names[$c['id']] = $c['function']['name'];
            foreach ($m['_provider']['gemini'] ?? [] as $part) if (isset($part['functionCall']['id'])) $nativeIds[$part['functionCall']['id']] = true;
            if ($protocol === 'anthropic') {
                $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
                $parts = $m['_provider']['anthropic'] ?? [];
                if (!$parts) {
                    if ($m['role'] === 'tool') $parts[] = ['type' => 'tool_result', 'tool_use_id' => $m['tool_call_id'], 'content' => $m['content']];
                    elseif (!empty($m['content'])) $parts[] = ['type' => 'text', 'text' => $m['content']];
                    foreach ($m['tool_calls'] ?? [] as $c) $parts[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['function']['name'], 'input' => json_decode($c['function']['arguments'])];
                }
                if ($parts && $history && end($history)['role'] === $role) $history[count($history)-1]['content'] = array_merge(end($history)['content'], $parts);
                elseif ($parts) $history[] = ['role' => $role, 'content' => $parts];
            } else {
                $role = $m['role'] === 'assistant' ? 'model' : 'user';
                $parts = $m['_provider']['gemini'] ?? [];
                if (!$parts) {
                    if ($m['role'] === 'tool') {
                        $response = ['name' => $names[$m['tool_call_id']] ?? 'unknown', 'response' => ['result' => json_decode($m['content'], true) ?? $m['content']]];
                        if (isset($nativeIds[$m['tool_call_id']])) $response['id'] = $m['tool_call_id'];
                        $parts[] = ['functionResponse' => $response];
                    }
                    elseif (!empty($m['content'])) $parts[] = ['text' => $m['content']];
                    foreach ($m['tool_calls'] ?? [] as $c) $parts[] = ['functionCall' => ['name' => $c['function']['name'], 'args' => json_decode($c['function']['arguments'])]];
                }
                if ($parts && $history && end($history)['role'] === $role) $history[count($history)-1]['parts'] = array_merge(end($history)['parts'], $parts);
                elseif ($parts) $history[] = ['role' => $role, 'parts' => $parts];
            }
        }
        if ($protocol === 'anthropic') {
            $payload = ['model' => $body['model'], 'messages' => $history, 'system' => implode("\n", $system), 'max_tokens' => $body['max_tokens'], 'stream' => true];
            if ($tools) $payload['tools'] = array_map(fn ($t) => ['name' => $t['function']['name'], 'description' => $t['function']['description'], 'input_schema' => $t['function']['parameters']], $tools);
            return [$base . '/messages', array_merge($headers, ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01']), $payload];
        }
        $payload = ['contents' => $history, 'systemInstruction' => ['parts' => [['text' => implode("\n", $system)]]], 'generationConfig' => ['maxOutputTokens' => $body['max_tokens']]];
        if ($tools) $payload['tools'] = [['functionDeclarations' => array_map(function ($t) { $f = $t['function']; $f['parameters'] = self::geminiSchema($f['parameters']); return $f; }, $tools)]];
        return [$base . '/models/' . rawurlencode(preg_replace('/^models\//', '', $body['model'])) . ':streamGenerateContent?alt=sse', array_merge($headers, ['x-goog-api-key: ' . $key]), $payload];
    }

    private static function geminiSchema($value)
    {
        if (!is_array($value)) return $value;
        unset($value['additionalProperties']);
        foreach ($value as &$item) $item = self::geminiSchema($item);
        return $value;
    }
}
