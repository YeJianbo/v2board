<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneTrafficStatistics extends Command
{
    protected $signature = 'traffic:prune-statistics
        {--minute-days=31 : Minute detail retention days}
        {--hour-days=366 : Hour detail retention days}
        {--daily-days=1095 : Daily detail retention days}
        {--batch=5000 : Rows deleted per transaction}';

    protected $description = 'Prune expired traffic statistics in bounded batches';

    public function handle(): int
    {
        $batch = max(100, min((int)$this->option('batch'), 20000));
        $policies = [
            'v2_stat_user_server_minute' => max(1, (int)$this->option('minute-days')),
            'v2_stat_user_server_hour' => max(1, (int)$this->option('hour-days')),
            'v2_stat_user_server' => max(1, (int)$this->option('daily-days')),
            'v2_stat_user' => max(1, (int)$this->option('daily-days')),
            'v2_stat_server' => max(1, (int)$this->option('daily-days')),
        ];

        foreach ($policies as $table => $days) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $deleted = $this->deleteInBatches($table, time() - ($days * 86400), $batch);
            $this->line("{$table}: {$deleted} rows deleted");
        }

        if (Schema::hasTable('v2_processing_batch')) {
            $deleted = DB::table('v2_processing_batch')
                ->where('created_at', '<', time() - 30 * 86400)
                ->delete();
            $this->line("v2_processing_batch: {$deleted} rows deleted");
        }

        return 0;
    }

    private function deleteInBatches(string $table, int $cutoff, int $batch): int
    {
        $deleted = 0;
        do {
            $ids = DB::table($table)
                ->where('record_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->all();
            if (!$ids) {
                break;
            }
            $count = DB::transaction(static function () use ($table, $ids) {
                return DB::table($table)->whereIn('id', $ids)->delete();
            }, 3);
            $deleted += (int)$count;
        } while (count($ids) === $batch);

        return $deleted;
    }
}
