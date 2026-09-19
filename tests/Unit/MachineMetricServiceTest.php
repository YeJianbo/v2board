<?php

namespace Tests\Unit;

use App\Models\Machine;
use App\Services\MachineMetricService;
use PHPUnit\Framework\TestCase;

class MachineMetricServiceTest extends TestCase
{
    public function test_it_builds_a_minute_resource_sample(): void
    {
        $machine = new Machine();
        $machine->id = 9;
        $service = new MachineMetricService();

        $sample = $service->buildSample($machine, [
            'cpu' => 21.345,
            'mem_total' => 1000,
            'mem_used' => 250,
            'disk_total' => 2000,
            'disk_used' => 1000,
            'swap_total' => 0,
            'load1' => 0.1239,
            'load5' => 0.25,
            'load15' => 0.5,
            'net_in_rate' => 1024.8,
            'net_out_rate' => 2048.2,
        ], 1725000000);

        $this->assertSame(9, $sample['machine_id']);
        $this->assertSame(21.35, $sample['cpu']);
        $this->assertSame(25.0, $sample['memory']);
        $this->assertSame(50.0, $sample['disk']);
        $this->assertNull($sample['swap']);
        $this->assertSame(0.124, $sample['load_1']);
        $this->assertSame(1025, $sample['net_in_rate']);
        $this->assertSame(2048, $sample['net_out_rate']);
    }

    public function test_it_chooses_bounded_history_buckets(): void
    {
        $service = new MachineMetricService();

        $this->assertSame(60, $service->bucketSeconds(3600));
        $this->assertSame(180, $service->bucketSeconds(6 * 3600));
        $this->assertSame(600, $service->bucketSeconds(24 * 3600));
        $this->assertSame(3600, $service->bucketSeconds(7 * 86400));
        $this->assertSame(3600, $service->bucketSeconds(30 * 86400));
    }

    public function test_it_uses_sampler_peak_for_network_history_when_available(): void
    {
        $machine = new Machine();
        $machine->id = 9;
        $sample = (new MachineMetricService())->buildSample($machine, [
            'net_in_rate' => 1024,
            'net_out_rate' => 2048,
            'net_in_sample_peak' => 8192.4,
            'net_out_sample_peak' => 16384.6,
        ], 1725000000);

        $this->assertSame(8192, $sample['net_in_rate']);
        $this->assertSame(16385, $sample['net_out_rate']);
    }

    public function test_it_marks_offline_gaps_and_sustained_resource_thresholds(): void
    {
        $service = new MachineMetricService();
        $records = [
            ['recorded_at' => 1000, 'cpu' => 95, 'memory' => 20, 'disk' => 30],
            ['recorded_at' => 1060, 'cpu' => 96, 'memory' => 20, 'disk' => 30],
            ['recorded_at' => 1120, 'cpu' => 97, 'memory' => 20, 'disk' => 30],
            ['recorded_at' => 1180, 'cpu' => 98, 'memory' => 20, 'disk' => 30],
            ['recorded_at' => 1600, 'cpu' => 20, 'memory' => 20, 'disk' => 30],
        ];

        $annotations = $service->buildAnnotations($records, 60, [
            'offline_seconds' => 300,
            'resource_enabled' => true,
            'resource_duration_seconds' => 180,
            'dynamic_network_spikes' => false,
            'metrics' => [
                'cpu' => [
                    'enabled' => true,
                    'threshold' => 90,
                    'label' => 'CPU',
                    'category' => 'usage',
                    'unit' => '%',
                ],
            ],
        ]);

        $this->assertContains('offline', array_column($annotations, 'type'));
        $this->assertContains('threshold-cpu', array_column($annotations, 'type'));
    }

    public function test_it_marks_dynamic_network_spikes_without_a_fixed_network_threshold(): void
    {
        $service = new MachineMetricService();
        $records = [];
        for ($index = 0; $index < 8; $index++) {
            $records[] = [
                'recorded_at' => 1000 + $index * 60,
                'net_in_rate' => $index === 5 ? 2 * 1024 * 1024 : 1000,
                'net_out_rate' => 500,
            ];
        }

        $annotations = $service->buildAnnotations($records, 60, [
            'offline_seconds' => 300,
            'resource_enabled' => false,
            'dynamic_network_spikes' => true,
        ]);

        $this->assertContains('threshold-network', array_column($annotations, 'type'));
    }
}
