<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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

        $attempt = 0;
        $maxAttempts = 3;

        while ($attempt < $maxAttempts) {
            try {
                DB::beginTransaction();

                $now = time();
                foreach ($this->data as $userId => $trafficData) {
                    $u = (int) ($trafficData[0] ?? 0);
                    $d = (int) ($trafficData[1] ?? 0);

                    if ($u <= 0 && $d <= 0) {
                        continue;
                    }

                    $this->incrementStatTable(
                        'v2_stat_user_server',
                        (int) $userId,
                        $serverId,
                        $serverType,
                        $serverRate,
                        $u,
                        $d,
                        $this->recordType,
                        $dayRecordAt,
                        $now
                    );
                    $this->incrementStatTable(
                        'v2_stat_user_server_hour',
                        (int) $userId,
                        $serverId,
                        $serverType,
                        $serverRate,
                        $u,
                        $d,
                        'h',
                        $hourRecordAt,
                        $now
                    );
                    $this->incrementStatTable(
                        'v2_stat_user_server_minute',
                        (int) $userId,
                        $serverId,
                        $serverType,
                        $serverRate,
                        $u,
                        $d,
                        'm',
                        $minuteRecordAt,
                        $now
                    );
                }

                DB::commit();
                return;
            } catch (\Exception $e) {
                DB::rollBack();
                if (strpos($e->getMessage(), '40001') !== false || strpos(strtolower($e->getMessage()), 'deadlock') !== false) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        sleep(pow(2, $attempt));
                        continue;
                    }
                }
                throw new \RuntimeException('用户节点统计数据失败' . $e->getMessage(), 0, $e);
            }
        }
    }

    private function incrementStatTable($table, $userId, $serverId, $serverType, $serverRate, $u, $d, $recordType, $recordAt, $now)
    {
        DB::statement(
            "INSERT INTO {$table}
                (user_id, server_id, server_type, server_rate, u, d, record_type, record_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                u = u + VALUES(u),
                d = d + VALUES(d),
                updated_at = VALUES(updated_at)",
            [
                $userId,
                $serverId,
                $serverType,
                $serverRate,
                $u,
                $d,
                $recordType,
                $recordAt,
                $now,
                $now,
            ]
        );
    }
}
