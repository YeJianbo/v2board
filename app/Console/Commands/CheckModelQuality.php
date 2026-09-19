<?php

namespace App\Console\Commands;

use App\Services\ModelQualityService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckModelQuality extends Command
{
    protected $signature = 'model-relay:quality';
    protected $description = 'Process manually queued and explicitly enabled scheduled model quality tests';
    public function handle(ModelQualityService $service): int
    {
        if (!Schema::hasTable('v2_model_quality_run')) return 0;
        DB::table('v2_model_quality_run')->where('status', 'running')->where('updated_at', '<', Carbon::now('UTC')->subMinutes(15))
            ->update(['status' => 'error', 'finished_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC')]);
        foreach (DB::table('v2_model_quality_config')->where('next_run_at', '<=', Carbon::now('UTC'))->limit(20)->get() as $config) {
            $s = json_decode($config->settings, true);
            try { if ($s['enabled']) $service->enqueue($config->upstream_id, 'scheduled'); } catch (\Throwable $e) { $this->warn('Skipped unavailable upstream #' . $config->upstream_id); }
            DB::table('v2_model_quality_config')->where('upstream_id', $config->upstream_id)->update(['next_run_at' => $s['enabled'] ? Carbon::now('UTC')->addMinutes($s['interval_minutes']) : null]);
        }
        foreach (DB::table('v2_model_quality_run')->where('status', 'queued')->orderBy('id')->limit(2)->pluck('id') as $id) {
            try { $service->execute($id); } catch (\Throwable $e) {
                DB::table('v2_model_quality_run')->where('id', $id)->update(['status' => 'error', 'finished_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC')]);
                $this->warn('Quality run #' . $id . ' failed');
            }
        }
        $old = DB::table('v2_model_quality_run')->whereNotIn('status', ['queued', 'running'])->where('created_at', '<', Carbon::now('UTC')->subDays(90))
            ->whereNotIn('id', DB::table('v2_model_quality_config')->whereNotNull('baseline_run_id')->select('baseline_run_id'))->limit(1000)->pluck('id');
        DB::table('v2_model_quality_run')->whereIn('id', $old)->delete();
        return 0;
    }
}
