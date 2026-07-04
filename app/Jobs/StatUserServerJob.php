<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\StatisticalService;

class StatUserServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    public function handle()
    {
        $dayRecordAt = strtotime(date('Y-m-d'));
        $hourRecordAt = strtotime(date('Y-m-d H:00:00'));
        $minuteRecordAt = strtotime(date('Y-m-d H:i:00'));
        $serverId = (int) ($this->server['id'] ?? 0);
        $serverRate = (string) ($this->server['rate'] ?? '1.00');
        $serverType = strtolower((string) $this->protocol);

        if ($serverId <= 0 || $serverType === '') {
            return;
        }

        $service = app(StatisticalService::class);

        foreach ($this->data as $userId => $trafficData) {
            $u = (int) ($trafficData[0] ?? 0);
            $d = (int) ($trafficData[1] ?? 0);

            if ($u <= 0 && $d <= 0) {
                continue;
            }

            $service->statUserServer(
                'v2_stat_user_server',
                $dayRecordAt,
                (int) $userId,
                $serverId,
                $serverType,
                $serverRate,
                $u,
                $d,
            );
            $service->statUserServer(
                'v2_stat_user_server_hour',
                $hourRecordAt,
                (int) $userId,
                $serverId,
                $serverType,
                $serverRate,
                $u,
                $d,
            );
            $service->statUserServer(
                'v2_stat_user_server_minute',
                $minuteRecordAt,
                (int) $userId,
                $serverId,
                $serverType,
                $serverRate,
                $u,
                $d,
            );
        }
    }
}
