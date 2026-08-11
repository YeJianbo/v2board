<?php

namespace App\Console\Commands;

use App\Services\StatisticalService;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        if (Redis::exists('traffic_reset_lock')) {
            return;
        }

        $this->flushStatCaches();

        $uploads = Redis::hgetall('v2board_upload_traffic');
        Redis::del('v2board_upload_traffic');
        $downloads = Redis::hgetall('v2board_download_traffic');
        Redis::del('v2board_download_traffic');
        if (empty($uploads) && empty($downloads)) {
            return;
        }

        $userIds = array_values(array_unique(array_merge(array_keys($uploads), array_keys($downloads))));
        $users = User::whereIn('id', $userIds)->get([
            'id',
            'u',
            'd',
            'transfer_enable',
            'expired_at',
            'remind_traffic',
            'telegram_id',
        ]);
        $time = time();
        $casesU = [];
        $casesD = [];
        $idList = [];

        foreach ($users as $user) {
            $upload = $uploads[$user->id] ?? 0;
            $download = $downloads[$user->id] ?? 0;

            $user->u = (int) $user->u + (int) $upload;
            $user->d = (int) $user->d + (int) $download;
            $casesU[] = "WHEN {$user->id} THEN " . $user->u;
            $casesD[] = "WHEN {$user->id} THEN " . $user->d;
            $idList[] = $user->id;
        }

        if (!$idList) {
            return;
        }

        $idListStr = implode(',', $idList);
        $casesUStr = implode(' ', $casesU);
        $casesDStr = implode(' ', $casesD);
        $sql = "UPDATE v2_user SET u = CASE id {$casesUStr} END, d = CASE id {$casesDStr} END, t = {$time}, updated_at = {$time} WHERE id IN ({$idListStr})";
        try {
            DB::beginTransaction();
            DB::statement($sql);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('流量更新失败: ' . $e->getMessage());
            return;
        }

        $notificationService = app(TelegramNotificationService::class);
        foreach ($users as $user) {
            try {
                $notificationService->remindUserTraffic($user);
            } catch (\Throwable $e) {
                \Log::warning('Telegram流量提醒处理失败', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function flushStatCaches(): void
    {
        $service = app(StatisticalService::class);
        $now = time();
        $days = array_unique([
            strtotime(date('Y-m-d', $now)),
            strtotime(date('Y-m-d', $now - 86400)),
        ]);
        $hours = [];
        for ($i = 0; $i <= 2; $i++) {
            $hours[] = strtotime(date('Y-m-d H:00:00', $now - ($i * 3600)));
        }
        $minutes = [];
        for ($i = 0; $i <= 5; $i++) {
            $minutes[] = strtotime(date('Y-m-d H:i:00', $now - ($i * 60)));
        }

        try {
            foreach ($days as $recordAt) {
                if ($recordAt <= 0) {
                    continue;
                }
                $service->flushStatServer($recordAt, 'd');
                $service->flushStatUser($recordAt, 'd');
                $service->flushStatUserServer('v2_stat_user_server', $recordAt, 'd');
            }

            foreach (array_unique($hours) as $recordAt) {
                if ($recordAt > 0) {
                    $service->flushStatUserServer('v2_stat_user_server_hour', $recordAt, 'h');
                }
            }

            foreach (array_unique($minutes) as $recordAt) {
                if ($recordAt > 0) {
                    $service->flushStatUserServer('v2_stat_user_server_minute', $recordAt, 'm');
                }
            }
        } catch (\Throwable $e) {
            \Log::error('统计缓存落库失败: ' . $e->getMessage());
        }
    }
}
