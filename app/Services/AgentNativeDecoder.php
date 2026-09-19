<?php

namespace App\Services;

class AgentNativeDecoder
{
    private string $buffer = '';
    private string $text = '';
    private array $blocks = [];
    private array $arguments = [];
    private array $output = [];
    private bool $done = false;
    private string $protocol;
    private $emit;

    public function __construct(string $protocol, callable $emit) { $this->protocol = $protocol; $this->emit = $emit; }
    public function push(string $chunk): void
    {
        $this->buffer = str_replace("\r\n", "\n", $this->buffer . $chunk);
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $end); $this->buffer = substr($this->buffer, $end + 2);
            $lines = [];
            foreach (explode("\n", $frame) as $line) if (str_starts_with($line, 'data:')) $lines[] = ltrim(substr($line, 5));
            if (!$lines || implode('', $lines) === '[DONE]') continue;
            $event = json_decode(implode("\n", $lines), true);
            abort_unless(is_array($event), 502, '模型流式响应不是有效 JSON');
            $this->event($event);
        }
    }
    private function append(string $text): void { $this->text .= $text; if ($text !== '') ($this->emit)('text', ['text' => $text]); }
    private function event(array $e): void
    {
        abort_if(isset($e['error']) || in_array($e['type'] ?? '', ['error', 'response.failed', 'response.incomplete'], true), 502, '模型协议返回错误或输出未完成');
        if ($this->protocol === 'responses') {
            if (($e['type'] ?? '') === 'response.output_text.delta') $this->append($e['delta'] ?? '');
            if (($e['type'] ?? '') === 'response.completed') {
                abort_unless(($e['response']['status'] ?? '') === 'completed', 502, '模型输出未完成');
                $this->output = $e['response']['output'] ?? []; $this->done = true;
                if ($this->text === '') foreach ($this->output as $item) foreach ($item['content'] ?? [] as $part) if (($part['type'] ?? '') === 'output_text') $this->append($part['text']);
            }
        } elseif ($this->protocol === 'anthropic') {
            $i = $e['index'] ?? 0;
            if (($e['type'] ?? '') === 'content_block_start') $this->blocks[$i] = $e['content_block'];
            if (($e['type'] ?? '') === 'content_block_delta') {
                $d = $e['delta'];
                if ($d['type'] === 'text_delta') { $this->append($d['text']); $this->blocks[$i]['text'] = ($this->blocks[$i]['text'] ?? '') . $d['text']; }
                if ($d['type'] === 'input_json_delta') $this->arguments[$i] = ($this->arguments[$i] ?? '') . $d['partial_json'];
                if ($d['type'] === 'thinking_delta') $this->blocks[$i]['thinking'] = ($this->blocks[$i]['thinking'] ?? '') . $d['thinking'];
                if ($d['type'] === 'signature_delta') $this->blocks[$i]['signature'] = ($this->blocks[$i]['signature'] ?? '') . $d['signature'];
            }
            if (($e['type'] ?? '') === 'message_delta') abort_if(in_array($e['delta']['stop_reason'] ?? '', ['max_tokens', 'refusal'], true), 502, '模型输出未完成或已拒绝');
            if (($e['type'] ?? '') === 'message_stop') $this->done = true;
        } else {
            $candidate = $e['candidates'][0] ?? [];
            abort_if(isset($e['promptFeedback']['blockReason']), 502, '模型拒绝了本次请求');
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                $this->blocks[] = $part;
                if (isset($part['text']) && empty($part['thought'])) $this->append($part['text']);
            }
            if (isset($candidate['finishReason'])) { abort_unless($candidate['finishReason'] === 'STOP', 502, '模型输出未完成或被拦截'); $this->done = true; }
        }
    }
    public function message(): array
    {
        abort_unless($this->done, 502, '模型流式响应提前中断，可重试本轮');
        $calls = [];
        if ($this->protocol === 'responses') {
            foreach ($this->output as $item) if (($item['type'] ?? '') === 'function_call') $calls[] = ['id' => $item['call_id'], 'type' => 'function', 'function' => ['name' => $item['name'], 'arguments' => $item['arguments']]];
            $blocks = $this->output;
        } elseif ($this->protocol === 'anthropic') {
            foreach ($this->blocks as $i => &$block) if ($block['type'] === 'tool_use') {
                if (isset($this->arguments[$i])) $block['input'] = json_decode($this->arguments[$i], false, 512, JSON_THROW_ON_ERROR);
                $calls[] = ['id' => $block['id'], 'type' => 'function', 'function' => ['name' => $block['name'], 'arguments' => json_encode((object) $block['input'], JSON_THROW_ON_ERROR)]];
            }
            unset($block); $blocks = array_values($this->blocks);
        } else {
            foreach ($this->blocks as $part) if (isset($part['functionCall'])) {
                $f = $part['functionCall'];
                $calls[] = ['id' => $f['id'] ?? ('call_' . bin2hex(random_bytes(12))), 'type' => 'function', 'function' => ['name' => $f['name'], 'arguments' => json_encode((object) ($f['args'] ?? []), JSON_THROW_ON_ERROR)]];
            }
            $blocks = $this->blocks;
        }
        return ['role' => 'assistant', 'content' => $this->text, 'tool_calls' => $calls, '_provider' => [$this->protocol => $blocks]];
    }
}
