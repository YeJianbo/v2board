<?php

namespace App\Console\Commands;

use App\Services\ModelRelayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneModelRelay extends Command
{
    protected $signature = 'model-relay:maintain';
    protected $description = 'Settle interrupted relay reservations and retain 90 days of request details';
    public function handle(ModelRelayService $service): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_model_usage')) return 0;
        $pending = DB::table('v2_model_usage')->where('status', 'pending')->where('created_at', '<', \Carbon\Carbon::now('UTC')->subMinutes(20))->limit(500)->pluck('request_id');
        foreach ($pending as $id) $service->settle(['request_id' => $id, 'status' => 'unknown', 'http_status' => 0, 'duration_ms' => 1200000]);
        $old = DB::table('v2_model_usage')->where('status', '!=', 'pending')->where('created_at', '<', \Carbon\Carbon::now('UTC')->subDays(90))->limit(5000)->pluck('request_id');
        DB::table('v2_model_usage')->whereIn('request_id', $old)->delete();
        return 0;
    }
}
