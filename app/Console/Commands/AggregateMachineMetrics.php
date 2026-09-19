<?php

namespace App\Console\Commands;

use App\Services\MachineMetricService;
use Illuminate\Console\Command;

class AggregateMachineMetrics extends Command
{
    protected $signature = 'machine:aggregate-metrics
        {--hours=2 : Number of completed hours to rebuild}
        {--end-at= : Optional Unix timestamp used as the aggregation boundary}';
    protected $description = 'Aggregate minute machine resource metrics into hourly rows';

    public function handle(MachineMetricService $metrics): int
    {
        $hours = max(1, min(MachineMetricService::HOURLY_RETENTION_DAYS * 24, (int) $this->option('hours')));
        $endAt = $this->option('end-at');
        $endAt = is_numeric($endAt) ? (int) $endAt : null;
        $result = $metrics->aggregateHourly($hours, $endAt);

        $this->info(sprintf(
            'Aggregated %d machine-hour rows from %s to %s.',
            $result['rows'],
            date('Y-m-d H:i:s', $result['started_at']),
            date('Y-m-d H:i:s', $result['end_at'])
        ));

        return 0;
    }
}
