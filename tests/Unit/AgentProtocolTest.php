<?php
namespace Tests\Unit;

use App\Services\AgentProtocol;
use App\Services\AgentNativeDecoder;
use Tests\TestCase;

class AgentProtocolTest extends TestCase
{
    private function decode(string $protocol, array $events): array
    {
        $d = new AgentNativeDecoder($protocol, fn () => null);
        foreach ($events as $event) foreach (str_split('data: ' . json_encode($event) . "\n\n", 3) as $part) $d->push($part);
        return $d->message();
    }
    public function test_native_tool_rounds_and_auth_headers(): void
    {
        $fixtures = [
            'responses' => [['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'nodes_list', 'arguments' => '{}']]]]],
            'anthropic' => [['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'nodes_list', 'input' => new \stdClass()]], ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']], ['type' => 'message_stop']],
            'gemini' => [['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'nodes_list', 'args' => new \stdClass()], 'thoughtSignature' => 'signature-test']]], 'finishReason' => 'STOP']]]],
        ];
        foreach ($fixtures as $protocol => $events) {
            $m = $this->decode($protocol, $events);
            $this->assertSame('nodes_list', $m['tool_calls'][0]['function']['name']);
            $this->assertSame('{}', $m['tool_calls'][0]['function']['arguments']);
            $body = ['model' => 'test-model', 'max_tokens' => 1800, 'messages' => [['role' => 'system', 'content' => 'test'], ['role' => 'user', 'content' => 'list'], $m, ['role' => 'tool', 'tool_call_id' => $m['tool_calls'][0]['id'], 'content' => '{"count":1}']], 'tools' => [['type' => 'function', 'function' => ['name' => 'nodes_list', 'description' => 'list', 'parameters' => ['type' => 'object', 'properties' => new \stdClass()]]]]];
            [$url, $headers, $payload] = AgentProtocol::request($protocol, ['base_url' => 'https://example.com/v1', 'api_key' => 'secret'], $body);
            $this->assertStringNotContainsString('secret', $url);
            $this->assertStringNotContainsString('secret', json_encode($payload));
            if ($protocol === 'responses') { $this->assertContains('Authorization: Bearer secret', $headers); $this->assertSame('function_call_output', $payload['input'][3]['type']); }
            if ($protocol === 'anthropic') { $this->assertContains('x-api-key: secret', $headers); $this->assertSame('tool_result', $payload['messages'][2]['content'][0]['type']); }
            if ($protocol === 'gemini') { $this->assertContains('x-goog-api-key: secret', $headers); $this->assertSame('signature-test', $payload['contents'][1]['parts'][0]['thoughtSignature']); $this->assertSame('nodes_list', $payload['contents'][2]['parts'][0]['functionResponse']['name']); }
        }
    }
    public function test_incomplete_stream_is_rejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->decode('responses', [['type' => 'response.output_text.delta', 'delta' => 'partial']]);
    }
    public function test_native_text_is_emitted_without_private_thoughts(): void
    {
        $events = []; $d = new AgentNativeDecoder('gemini', function ($type, $data) use (&$events) { $events[] = $data; });
        $d->push('data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'private', 'thought' => true], ['text' => 'answer']]], 'finishReason' => 'STOP']]]) . "\n\n");
        $this->assertSame('answer', $d->message()['content']);
        $this->assertStringNotContainsString('private', json_encode($events));
    }
}
