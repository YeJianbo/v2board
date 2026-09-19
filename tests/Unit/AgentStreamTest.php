<?php

namespace Tests\Unit;

use App\Services\AgentStreamDecoder;
use PHPUnit\Framework\TestCase;

class AgentStreamTest extends TestCase
{
    public function test_split_sse_and_tool_arguments_never_emit_private_reasoning(): void
    {
        $events = []; $decoder = new AgentStreamDecoder(function ($event, $data) use (&$events) { $events[] = [$event, $data]; });
        $frames = [
            ['choices' => [['delta' => ['reasoning_content' => 'PRIVATE_REASONING']]]],
            ['choices' => [['delta' => ['content' => '正在查询']]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'nodes_list', 'arguments' => '{']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '}']]]]]]],
        ];
        $wire = implode('', array_map(fn ($frame) => 'data: ' . json_encode($frame, JSON_UNESCAPED_UNICODE) . "\r\n\r\n", $frames)) . "data: [DONE]\r\n\r\n";
        foreach (str_split($wire, 3) as $chunk) $decoder->push($chunk);
        $message = $decoder->message();
        $this->assertSame('{}', $message['tool_calls'][0]['function']['arguments']);
        $this->assertSame('正在查询', $message['content']);
        $this->assertSame('PRIVATE_REASONING', $message['reasoning_content']);
        $this->assertStringNotContainsString('PRIVATE_REASONING', json_encode($events));
    }
    public function test_missing_terminal_marker_is_not_reported_as_success(): void
    {
        $decoder = new AgentStreamDecoder(fn () => null);
        $decoder->push("data: {\"choices\":[{\"delta\":{\"content\":\"partial\"}}]}\n\n");
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $decoder->message();
    }
    public function test_empty_id_in_argument_delta_preserves_tool_call_identity(): void
    {
        $decoder = new AgentStreamDecoder(fn () => null);
        foreach ([
            ['index' => 0, 'id' => 'call_grok_1', 'function' => ['name' => 'nodes_list', 'arguments' => '']],
            ['index' => 0, 'id' => '', 'type' => '', 'function' => ['name' => '', 'arguments' => '{}']],
        ] as $call) {
            $decoder->push('data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [$call]]]]]) . "\n\n");
        }
        $decoder->push("data: [DONE]\n\n");
        $call = $decoder->message()['tool_calls'][0];
        $this->assertSame('call_grok_1', $call['id']);
        $this->assertSame('nodes_list', $call['function']['name']);
        $this->assertSame('{}', $call['function']['arguments']);
    }
}
