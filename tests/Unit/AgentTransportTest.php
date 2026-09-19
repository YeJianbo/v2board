<?php

namespace Tests\Unit;

use App\Services\AgentHttpTransport;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentTransportTest extends TestCase
{
    private ?Process $server = null;
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Log::spy();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $this->url = 'http://' . $address;
        $this->server = new Process([PHP_BINARY, '-d', 'output_buffering=0', '-d', 'max_execution_time=0', '-S', $address, dirname(__DIR__) . '/Fixtures/agent-stream.php']);
        $this->server->setTimeout(null); $this->server->start();
        $deadline = microtime(true) + 15;
        do {
            $socket = @stream_socket_client('tcp://' . $address, $code, $error, 0.1);
            if ($socket) { fclose($socket); return; }
            usleep(100000);
        } while ($this->server->isRunning() && microtime(true) < $deadline);
        $this->fail('Fixture server did not start: ' . $this->server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0); parent::tearDown();
    }

    public function test_native_curl_stream_reports_text_and_heartbeats(): void
    {
        $events = [];
        $response = (new AgentHttpTransport())->stream($this->url, 'fixture', [], function ($type, $data) use (&$events) { $events[] = $type; });
        $this->assertSame('stream-ok', $response->json('choices.0.message.content'));
        $this->assertContains('heartbeat', $events); $this->assertContains('text', $events);
    }

    public function test_progress_callback_cancels_a_stream_after_first_chunk(): void
    {
        $received = false;
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new AgentHttpTransport())->stream($this->url . '/?slow=1', 'fixture', [], function ($type, $data) use (&$received) {
            if ($type === 'text') $received = true;
            if ($type === 'heartbeat' && $received) abort(499, 'stopped');
        });
    }

    public function test_interrupted_response_is_not_misreported_as_connection_failure(): void
    {
        try {
            (new AgentHttpTransport())->stream($this->url . '/?slow=1', 'fixture', [], fn () => null, 1);
            $this->fail('Expected the explicitly bounded test request to expire');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertStringContainsString('模型响应未完成', $e->getMessage());
            $this->assertStringContainsString('HTTP 200', $e->getMessage());
        }
    }

    /** @group slow */
    public function test_default_generation_waits_past_sixty_seconds(): void
    {
        $start = microtime(true);
        $response = (new AgentHttpTransport())->stream($this->url . '/?late=1', 'fixture', [], fn () => null);
        $this->assertSame('stream-ok', $response->json('choices.0.message.content'));
        $this->assertGreaterThan(60, microtime(true) - $start);
    }
}
