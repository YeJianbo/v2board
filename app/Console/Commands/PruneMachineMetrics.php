<?php

namespace App\Console\Commands;

use App\Services\MachineMetricService;
use Illuminate\Console\Command;

class PruneMachineMetrics extends Command
{
    protected $signature = 'machine:prune-metrics
        {--days= : Legacy alias for minute retention days}
        {--minute-days=7 : Minute metric retention days}
        {--hour-days=30 : Hourly metric retention days}
        {--batch=5000 : Rows per delete batch}';
    protected $description = 'Prune expired machine resource metrics';

    public function handle(MachineMetricService $metrics): int
    {
        $legacyDays = $this->option('days');
        $minuteDays = is_numeric($legacyDays)
            ? (int) $legacyDays
            : (int) $this->option('minute-days');
        $batchSize = (int) $this->option('batch');
        $minuteDeleted = $metrics->prune($minuteDays, $batchSize);
        $hourDeleted = $metrics->pruneHourly((int) $this->option('hour-days'), $batchSize);
        $this->info(sprintf(
            'Deleted %d minute rows and %d hourly rows.',
            $minuteDeleted,
            $hourDeleted
        ));

        return 0;
    }
}
