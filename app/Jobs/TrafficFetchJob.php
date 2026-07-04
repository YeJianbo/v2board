<?php

namespace App\Jobs;

use App\Services\StatisticalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $rate = $this->server['rate'] ?? 1;
        $serverId = (int) ($this->server['id'] ?? 0);
        $serverType = strtolower((string) $this->protocol);
        $recordAt = strtotime(date('Y-m-d'));
        $hourRecordAt = strtotime(date('Y-m-d H:00:00'));
        $minuteRecordAt = strtotime(date('Y-m-d H:i:00'));
        $statService = app(StatisticalService::class);
        $statService->setStartAt($recordAt);
        $serverUpload = 0;
        $serverDownload = 0;

        foreach (array_keys($this->data) as $userId) {
            $upload = (int) ($this->data[$userId][0] ?? 0);
            $download = (int) ($this->data[$userId][1] ?? 0);
            if ($upload <= 0 && $download <= 0) {
                continue;
            }

            Redis::hincrby('v2board_upload_traffic', $userId, (int) ($upload * $rate));
            Redis::hincrby('v2board_download_traffic', $userId, (int) ($download * $rate));

            $statService->statUser($rate, (int) $userId, $upload, $download);
            if ($serverId > 0 && $serverType !== '') {
                $statService->statUserServer('v2_stat_user_server', $recordAt, (int) $userId, $serverId, $serverType, $rate, $upload, $download);
                $statService->statUserServer('v2_stat_user_server_hour', $hourRecordAt, (int) $userId, $serverId, $serverType, $rate, $upload, $download);
                $statService->statUserServer('v2_stat_user_server_minute', $minuteRecordAt, (int) $userId, $serverId, $serverType, $rate, $upload, $download);
            }

            $serverUpload += $upload;
            $serverDownload += $download;
        }

        if ($serverId > 0 && $serverType !== '' && ($serverUpload > 0 || $serverDownload > 0)) {
            $statService->statServer($serverId, $serverType, $serverUpload, $serverDownload);
        }
    }
}
