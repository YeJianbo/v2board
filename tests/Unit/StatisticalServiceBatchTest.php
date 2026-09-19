<?php

namespace Tests\Unit;

use App\Services\ProcessingBatchService;
use App\Services\StatisticalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class StatisticalServiceBatchTest extends TestCase
{
    public function test_it_restores_a_batch_when_the_database_transaction_fails(): void
    {
        $batch = ['batch_id' => 'failed-batch'];
        $batchService = Mockery::mock(ProcessingBatchService::class);
        $batchService->shouldReceive('move')
            ->once()
            ->andReturn([$batch, ['vless|1|u' => 100, 'vless|1|d' => 200]]);
        $batchService->shouldReceive('record')->never();
        $batchService->shouldReceive('restore')->once()->with($batch);
        $batchService->shouldReceive('complete')->never();

        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \RuntimeException('database unavailable'));

        $service = $this->serviceWithBatchService($batchService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');
        $service->flushStatServer(1725000000, 'd');
    }

    public function test_it_never_restores_a_batch_after_the_database_commit(): void
    {
        $batch = ['batch_id' => 'committed-batch'];
        $batchService = Mockery::mock(ProcessingBatchService::class);
        $batchService->shouldReceive('move')
            ->once()
            ->andReturn([$batch, ['vless|1|u' => 100, 'vless|1|d' => 200]]);
        $batchService->shouldReceive('record')->once()->with('committed-batch', 'stat_server');
        $batchService->shouldReceive('complete')
            ->once()
            ->with($batch)
            ->andThrow(new \RuntimeException('redis unavailable'));
        $batchService->shouldReceive('restore')->never();

        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn ($callback) => $callback());
        DB::shouldReceive('statement')->once()->andReturnTrue();
        Log::shouldReceive('warning')->once();

        $service = $this->serviceWithBatchService($batchService);
        $service->flushStatServer(1725000000, 'd');

        $this->assertTrue(true);
    }

    private function serviceWithBatchService(ProcessingBatchService $batchService): StatisticalService
    {
        $reflection = new \ReflectionClass(StatisticalService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('batchService');
        $property->setAccessible(true);
        $property->setValue($service, $batchService);

        return $service;
    }
}
