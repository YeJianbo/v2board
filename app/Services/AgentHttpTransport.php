<?php

namespace App\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

class AgentHttpTransport
{
    public function stream(string $url, string $key, array $payload, callable $emit, int $timeout = 0, string $protocol = 'openai'): Response
    {
        abort_unless(function_exists('curl_init'), 503, 'PHP cURL 扩展不可用');
        $requestHeaders = ['Authorization: Bearer ' . $key, 'Accept: text/event-stream', 'Content-Type: application/json'];
        if ($protocol !== 'openai') [$url, $requestHeaders, $payload] = AgentProtocol::request($protocol, ['base_url' => $url, 'api_key' => $key], $payload);
        $curl = curl_init($url); $body = ''; $headers = []; $streaming = false; $lastPulse = 0;
        $decoder = $protocol === 'openai' ? new AgentStreamDecoder($emit) : new AgentNativeDecoder($protocol, $emit);
        if ($protocol === 'openai') $payload['stream'] = true;
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => max(0, $timeout),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_NOPROGRESS => false,
            // XFERINFOFUNCTION was only exposed by PHP 8.2; production also runs 8.1.
            (defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION) => function () use ($emit, &$lastPulse) {
                if (microtime(true) - $lastPulse >= 1) { $emit('heartbeat', []); $lastPulse = microtime(true); }
                return 0;
            },
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers, &$streaming, $emit) {
                if (str_starts_with($line, 'HTTP/')) { $headers = []; $streaming = false; }
                elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2); $headers[trim($name)][] = trim($value);
                    if (strtolower(trim($name)) === 'content-type') $streaming = str_contains(strtolower($value), 'text/event-stream');
                }
                elseif (trim($line) === '' && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200) $emit('waiting', ['label' => '上游已响应，等待模型输出']);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$streaming, $decoder) {
                abort_if(strlen($body) + strlen($chunk) > 1048576, 502, '模型响应过大');
                $body .= $chunk;
                if ($streaming && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200) $decoder->push($chunk);
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            if ($ok === false) {
                $code = curl_errno($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); $bytes = strlen($body);
                \Illuminate\Support\Facades\Log::warning('Agent transport failed', ['model' => $payload['model'] ?? null, 'curl_errno' => $code, 'http_status' => $status,
                    'received_bytes' => $bytes, 'duration_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000)]);
                $reason = $bytes > 0 ? '上游连接中断，模型响应未完成' : ($status === 200 ? '上游已响应，但连接中断前没有返回模型内容' : '未收到上游响应，连接失败');
                abort(502, $reason . "（cURL {$code}，HTTP {$status}，已收到 {$bytes} 字节）");
            }
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($streaming && $status === 200) $body = json_encode(['choices' => [['message' => $decoder->message()]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            else if ($status === 200) {
                abort_unless($protocol === 'openai', 502, '上游未返回指定协议的 SSE 流，请检查协议与 API 地址');
                $text = json_decode($body, true)['choices'][0]['message']['content'] ?? null;
                if (is_string($text) && $text !== '') $emit('text', ['text' => $text]);
            }
            return new Response(new PsrResponse($status, $headers, $body));
        } finally { curl_close($curl); }
    }

    public function post(string $url, string $key, array $payload): Response
    {
        abort_unless(function_exists('curl_init'), 503, 'PHP cURL 扩展不可用');
        $curl = curl_init($url);
        $body = ''; $headers = []; $tooLarge = false;
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                if (str_starts_with($line, 'HTTP/')) $headers = [];
                elseif (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[trim($name)][] = trim($value); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            abort_if($tooLarge, 502, '模型响应过大');
            if ($ok === false) throw new ConnectionException('模型连接失败，cURL 错误码 ' . curl_errno($curl));
            return new Response(new PsrResponse((int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $headers, $body));
        } finally { curl_close($curl); }
    }
}
