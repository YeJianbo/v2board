<?php

namespace App\Services;

class AgentStreamDecoder
{
    private string $buffer = '';
    private array $message = ['role' => 'assistant', 'content' => ''];
    private array $calls = [];
    private $emit;
    public bool $done = false;

    public function __construct(callable $emit) { $this->emit = $emit; }
    public function push(string $chunk): void
    {
        $this->buffer = str_replace("\r\n", "\n", $this->buffer . $chunk);
        abort_if(strlen($this->buffer) > 1048576, 502, '模型流式事件过大');
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $end); $this->buffer = substr($this->buffer, $end + 2);
            $lines = [];
            foreach (explode("\n", $frame) as $line) if (str_starts_with($line, 'data:')) $lines[] = ltrim(substr($line, 5));
            if (!$lines) continue;
            $data = implode("\n", $lines);
            if (trim($data) === '[DONE]') { $this->done = true; continue; }
            $event = json_decode($data, true);
            abort_unless(is_array($event), 502, '模型流式响应不是有效 JSON');
            abort_if(isset($event['error']), 502, '模型在生成过程中返回错误');
            $delta = $event['choices'][0]['delta'] ?? [];
            if (!empty($delta['content']) && is_string($delta['content'])) {
                $this->message['content'] .= $delta['content'];
                ($this->emit)('text', ['text' => $delta['content']]);
            }
            if (!empty($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                // DeepSeek may require this for the next tool round. It never enters UI/history/logs.
                $this->message['reasoning_content'] = ($this->message['reasoning_content'] ?? '') . $delta['reasoning_content'];
                ($this->emit)('reasoning', []);
            }
            if (!empty($delta['reasoning_summary']) && is_string($delta['reasoning_summary'])) ($this->emit)('summary', ['text' => mb_substr($delta['reasoning_summary'], 0, 2000)]);
            foreach ($delta['tool_calls'] ?? [] as $call) {
                $index = $call['index'] ?? null;
                abort_unless(is_int($index) && $index >= 0 && $index < 4, 502, '模型工具调用索引不正确');
                $old = $this->calls[$index] ?? ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                // Some compatible APIs send empty IDs on argument-only deltas.
                if (isset($call['id']) && $call['id'] !== '') $old['id'] = $call['id'];
                foreach (['name', 'arguments'] as $field) if (isset($call['function'][$field])) $old['function'][$field] .= $call['function'][$field];
                abort_if(strlen($old['function']['arguments']) > 65536, 502, '模型工具参数过大');
                $this->calls[$index] = $old;
            }
            abort_if(strlen($this->message['content']) > 80000 || strlen($this->message['reasoning_content'] ?? '') > 524288, 502, '模型生成内容超过本次限制');
        }
    }
    public function message(): array
    {
        abort_unless($this->done, 502, '模型流式响应提前中断，可重试本轮');
        if ($this->calls) { ksort($this->calls); $this->message['tool_calls'] = array_values($this->calls); }
        return $this->message;
    }
}
