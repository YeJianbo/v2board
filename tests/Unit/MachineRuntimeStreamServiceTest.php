<?php

namespace Tests\Unit;

use App\Models\Machine;
use App\Services\MachineRuntimeStreamService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class MachineRuntimeStreamServiceTest extends TestCase
{
    private function createService(): MachineRuntimeStreamService
    {
        return new MachineRuntimeStreamService(new Repository(new ArrayStore()));
    }

    private function createMachine(int $id = 9): Machine
    {
        $machine = new Machine();
        $machine->forceFill(['id' => $id, 'name' => 'machine-' . $id]);
        $machine->exists = true;
        return $machine;
    }

    public function test_stream_returns_only_chunks_after_requested_sequence(): void
    {
        $service = $this->createService();
        $machine = $this->createMachine();
        $session = $service->start($machine, 'v2node');

        $this->assertSame('ravel', $session['service']);
        $this->assertTrue($service->append(9, [
            'session_id' => $session['session_id'],
            'logs' => "line one\n",
            'collected_at' => time(),
        ]));
        $this->assertTrue($service->append(9, [
            'session_id' => $session['session_id'],
            'logs' => "line two\n",
            'collected_at' => time(),
        ]));

        $result = $service->result(9, $session['session_id'], 1);
        $this->assertSame('streaming', $result['status']);
        $this->assertSame(2, $result['sequence']);
        $this->assertCount(1, $result['chunks']);
        $this->assertSame('line two', $result['chunks'][0]['logs']);
    }

    public function test_stream_rejects_another_machine_and_stops_explicitly(): void
    {
        $service = $this->createService();
        $session = $service->start($this->createMachine(), 'gost');

        $this->assertFalse($service->append(10, [
            'session_id' => $session['session_id'],
            'logs' => 'not allowed',
        ]));
        $this->assertNull($service->result(10, $session['session_id']));

        $stopped = $service->stop(9, $session['session_id']);
        $this->assertSame('stopped', $stopped['status']);
        $this->assertNull($service->activeForMachine(9));
    }

    public function test_stream_keeps_a_bounded_ring_buffer(): void
    {
        $service = $this->createService();
        $session = $service->start($this->createMachine(), 'ravel');
        for ($index = 0; $index < 12; $index++) {
            $service->append(9, [
                'session_id' => $session['session_id'],
                'logs' => str_repeat((string) ($index % 10), MachineRuntimeStreamService::MAX_CHUNK_BYTES),
            ]);
        }

        $result = $service->result(9, $session['session_id']);
        $bufferBytes = array_sum(array_map(
            fn (array $chunk) => strlen($chunk['logs']),
            $result['chunks']
        ));
        $this->assertLessThanOrEqual(MachineRuntimeStreamService::MAX_BUFFER_BYTES, $bufferBytes);
        $this->assertSame(12, $result['sequence']);
    }
}
