<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class StatisticalService
{
    protected $startAt;
    protected $endAt;
    protected $statServerKey;
    protected $statUserKey;
    protected $redis;

    public function __construct()
    {
        ini_set('memory_limit', -1);
        $this->redis = Redis::connection();
    }

    public function setStartAt($timestamp)
    {
        $this->startAt = (int) $timestamp;
        $this->statServerKey = "stat_server_{$this->startAt}";
        $this->statUserKey = "stat_user_{$this->startAt}";
    }

    public function setEndAt($timestamp)
    {
        $this->endAt = (int) $timestamp;
    }

    public function setServerStats()
    {
        $this->ensureStartAt();
    }

    public function setUserStats()
    {
        $this->ensureStartAt();
    }

    private function ensureStartAt(): void
    {
        if (!$this->startAt) {
            $this->setStartAt(strtotime(date('Y-m-d')));
        }
    }

    private function statUserServerKey(string $table, int $recordAt): string
    {
        return "{$table}_{$recordAt}";
    }

    private function encodeMember(array $parts): string
    {
        return implode('|', array_map(static fn ($part) => str_replace('|', '%7C', (string) $part), $parts));
    }

    private function decodeMember(string $member): array
    {
        return array_map(static fn ($part) => str_replace('%7C', '|', $part), explode('|', $member));
    }

    private function moveZsetForProcessing(string $key): array
    {
        if (!$this->redis->exists($key)) {
            return ['', []];
        }

        $processingKey = $key . ':processing:' . getmypid() . ':' . bin2hex(random_bytes(4));
        try {
            $this->redis->rename($key, $processingKey);
        } catch (\Throwable $e) {
            return ['', []];
        }

        return [$processingKey, $this->redis->zrange($processingKey, 0, -1, true) ?: []];
    }

    private function restoreProcessingZset(string $sourceKey, string $processingKey): void
    {
        if ($processingKey === '' || !$this->redis->exists($processingKey)) {
            return;
        }

        $raw = $this->redis->zrange($processingKey, 0, -1, true) ?: [];
        foreach ($raw as $member => $value) {
            $this->redis->zincrby($sourceKey, (float) $value, (string) $member);
        }
        $this->redis->del($processingKey);
    }

    public function generateStatData(): array
    {
        $startAt = $this->startAt;
        $endAt = $this->endAt;
        if (!$startAt || !$endAt) {
            $startAt = strtotime(date('Y-m-d'));
            $endAt = strtotime('+1 day', $startAt);
        }
        $data = [];
        $data['order_count'] = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
        $data['order_total'] = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->sum('total_amount');
        $data['paid_count'] = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->count();
        $data['paid_total'] = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');
        $commissionLogBuilder = CommissionLog::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $data['commission_count'] = $commissionLogBuilder->count();
        $data['commission_total'] = $commissionLogBuilder->sum('get_amount');
        $data['register_count'] = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
        $data['invite_count'] = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotNull('invite_user_id')
            ->count();
        $data['transfer_used_total'] = StatServer::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->select(DB::raw('SUM(u) + SUM(d) as total'))
            ->value('total') ?? 0;
        return $data;
    }

    public function statServer($serverId, $serverType, $u, $d): void
    {
        $this->ensureStartAt();
        $serverId = (int) $serverId;
        $serverType = strtolower(trim((string) $serverType));
        if ($serverId <= 0 || $serverType === '') {
            return;
        }

        $this->redis->zincrby($this->statServerKey, (int) $u, $this->encodeMember([$serverType, $serverId, 'u']));
        $this->redis->zincrby($this->statServerKey, (int) $d, $this->encodeMember([$serverType, $serverId, 'd']));
        $this->redis->expire($this->statServerKey, 86400 * 3);
    }

    public function statUser($rate, $userId, $u, $d): void
    {
        $this->ensureStartAt();
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        $rate = number_format((float) $rate, 2, '.', '');
        $this->redis->zincrby($this->statUserKey, (int) $u, $this->encodeMember([$rate, $userId, 'u']));
        $this->redis->zincrby($this->statUserKey, (int) $d, $this->encodeMember([$rate, $userId, 'd']));
        $this->redis->expire($this->statUserKey, 86400 * 3);
    }

    public function statUserServer(string $table, int $recordAt, int $userId, int $serverId, string $serverType, $rate, $u, $d): void
    {
        if ($recordAt <= 0 || $userId <= 0 || $serverId <= 0) {
            return;
        }

        $serverType = strtolower(trim($serverType));
        if ($serverType === '') {
            return;
        }

        $key = $this->statUserServerKey($table, $recordAt);
        $rate = number_format((float) $rate, 2, '.', '');
        $this->redis->zincrby($key, (int) $u, $this->encodeMember([$userId, $serverId, $serverType, $rate, 'u']));
        $this->redis->zincrby($key, (int) $d, $this->encodeMember([$userId, $serverId, $serverType, $rate, 'd']));
        $this->redis->expire($key, 86400 * 3);
    }

    public function getStatUserByUserID($userId): array
    {
        $this->ensureStartAt();
        $stats = [];
        $statsUser = $this->redis->zrange($this->statUserKey, 0, -1, true);
        foreach ($statsUser as $member => $value) {
            [$rate, $uid, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if ((int) $uid !== (int) $userId || !in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => (int) $uid,
            ];
            $stats[$key][$type] += (float) $value;
        }
        return array_values($stats);
    }

    public function getStatUser(): array
    {
        $this->ensureStartAt();
        $stats = [];
        $statsUser = $this->redis->zrange($this->statUserKey, 0, -1, true);
        foreach ($statsUser as $member => $value) {
            [$rate, $uid, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => (int) $uid,
            ];
            $stats[$key][$type] += (float) $value;
        }
        return array_values($stats);
    }

    public function getStatServer(): array
    {
        $this->ensureStartAt();
        $stats = [];
        $statsServer = $this->redis->zrange($this->statServerKey, 0, -1, true);
        foreach ($statsServer as $member => $value) {
            [$serverType, $serverId, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$serverType}_{$serverId}";
            $stats[$key] = $stats[$key] ?? [
                'server_id' => (int) $serverId,
                'server_type' => $serverType,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$key][$type] += (float) $value;
        }
        return array_values($stats);
    }

    public function clearStatUser(): void
    {
        $this->ensureStartAt();
        $this->redis->del($this->statUserKey);
    }

    public function clearStatServer(): void
    {
        $this->ensureStartAt();
        $this->redis->del($this->statServerKey);
    }

    public function flushStatServer(int $recordAt, string $recordType = 'd'): void
    {
        $this->setStartAt($recordAt);
        [$processingKey, $raw] = $this->moveZsetForProcessing($this->statServerKey);
        if (!$raw) {
            return;
        }

        $stats = $this->formatStatServerRows($raw);
        if (!$stats) {
            return;
        }

        try {
            DB::transaction(function () use ($stats, $recordAt, $recordType) {
                $now = time();
                foreach ($stats as $row) {
                    $this->upsertStatServer($row, $recordAt, $recordType, $now);
                }
            }, 3);
            $this->redis->del($processingKey);
        } catch (\Throwable $e) {
            $this->restoreProcessingZset($this->statServerKey, $processingKey);
            throw $e;
        }
    }

    public function flushStatUser(int $recordAt, string $recordType = 'd'): void
    {
        $this->setStartAt($recordAt);
        [$processingKey, $raw] = $this->moveZsetForProcessing($this->statUserKey);
        if (!$raw) {
            return;
        }

        $stats = $this->formatStatUserRows($raw);
        if (!$stats) {
            return;
        }

        try {
            DB::transaction(function () use ($stats, $recordAt, $recordType) {
                $now = time();
                foreach ($stats as $row) {
                    $this->upsertStatUser($row, $recordAt, $recordType, $now);
                }
            }, 3);
            $this->redis->del($processingKey);
        } catch (\Throwable $e) {
            $this->restoreProcessingZset($this->statUserKey, $processingKey);
            throw $e;
        }
    }

    public function flushStatUserServer(string $table, int $recordAt, string $recordType): void
    {
        $key = $this->statUserServerKey($table, $recordAt);
        [$processingKey, $raw] = $this->moveZsetForProcessing($key);
        if (!$raw) {
            return;
        }

        $stats = [];
        foreach ($raw as $member => $value) {
            [$userId, $serverId, $serverType, $rate, $type] = array_pad($this->decodeMember((string) $member), 5, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $statKey = "{$userId}|{$serverId}|{$serverType}|{$rate}";
            $stats[$statKey] = $stats[$statKey] ?? [
                'user_id' => (int) $userId,
                'server_id' => (int) $serverId,
                'server_type' => (string) $serverType,
                'server_rate' => (string) $rate,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$statKey][$type] += (float) $value;
        }

        if (!$stats) {
            $this->redis->del($processingKey);
            return;
        }

        try {
            DB::transaction(function () use ($table, $recordAt, $recordType, $stats) {
                $now = time();
                foreach ($stats as $row) {
                    $this->upsertStatUserServer($table, $row, $recordAt, $recordType, $now);
                }
            }, 3);
            $this->redis->del($processingKey);
        } catch (\Throwable $e) {
            $this->restoreProcessingZset($key, $processingKey);
            throw $e;
        }
    }

    private function formatStatServerRows(array $raw): array
    {
        $stats = [];
        foreach ($raw as $member => $value) {
            [$serverType, $serverId, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$serverType}_{$serverId}";
            $stats[$key] = $stats[$key] ?? [
                'server_id' => (int) $serverId,
                'server_type' => $serverType,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$key][$type] += (float) $value;
        }

        return array_values($stats);
    }

    private function formatStatUserRows(array $raw): array
    {
        $stats = [];
        foreach ($raw as $member => $value) {
            [$rate, $uid, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => (int) $uid,
            ];
            $stats[$key][$type] += (float) $value;
        }

        return array_values($stats);
    }

    private function upsertStatServer(array $row, int $recordAt, string $recordType, int $now): void
    {
        if ((float) $row['u'] <= 0 && (float) $row['d'] <= 0) {
            return;
        }

        DB::statement(
            "INSERT INTO v2_stat_server
                (server_id, server_type, u, d, record_type, record_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                u = u + VALUES(u),
                d = d + VALUES(d),
                updated_at = VALUES(updated_at)",
            [
                (int) $row['server_id'],
                (string) $row['server_type'],
                (int) $row['u'],
                (int) $row['d'],
                $recordType,
                $recordAt,
                $now,
                $now,
            ]
        );
    }

    private function upsertStatUser(array $row, int $recordAt, string $recordType, int $now): void
    {
        if ((float) $row['u'] <= 0 && (float) $row['d'] <= 0) {
            return;
        }

        DB::statement(
            "INSERT INTO v2_stat_user
                (user_id, server_rate, u, d, record_type, record_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                u = u + VALUES(u),
                d = d + VALUES(d),
                updated_at = VALUES(updated_at)",
            [
                (int) $row['user_id'],
                (string) $row['server_rate'],
                (int) $row['u'],
                (int) $row['d'],
                $recordType,
                $recordAt,
                $now,
                $now,
            ]
        );
    }

    private function upsertStatUserServer(string $table, array $row, int $recordAt, string $recordType, int $now): void
    {
        if ((float) $row['u'] <= 0 && (float) $row['d'] <= 0) {
            return;
        }

        DB::statement(
            "INSERT INTO {$table}
                (user_id, server_id, server_type, server_rate, u, d, record_type, record_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                u = u + VALUES(u),
                d = d + VALUES(d),
                updated_at = VALUES(updated_at)",
            [
                (int) $row['user_id'],
                (int) $row['server_id'],
                (string) $row['server_type'],
                (string) $row['server_rate'],
                (int) $row['u'],
                (int) $row['d'],
                $recordType,
                $recordAt,
                $now,
                $now,
            ]
        );
    }

    public function getStatRecord($type)
    {
        $startAt = $this->startAt ?: strtotime(date('Y-m-d', strtotime('-30 days')));
        $endAt = $this->endAt ?: strtotime('+1 day', strtotime(date('Y-m-d')));

        switch ($type) {
            case "paid_total": {
                return Stat::select(['*', DB::raw('paid_total / 100 as paid_total')])
                    ->where('record_at', '>=', $startAt)
                    ->where('record_at', '<', $endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "commission_total": {
                return Stat::select(['*', DB::raw('commission_total / 100 as commission_total')])
                    ->where('record_at', '>=', $startAt)
                    ->where('record_at', '<', $endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "register_count": {
                return Stat::where('record_at', '>=', $startAt)
                    ->where('record_at', '<', $endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
        }

        return collect();
    }

    public function getRanking($type, $limit = 20)
    {
        switch ($type) {
            case 'server_traffic_rank':
                return $this->buildServerTrafficRank($limit);
            case 'user_consumption_rank':
                return $this->buildUserConsumptionRank($limit);
            case 'invite_rank':
                return $this->buildInviteRank($limit);
        }
    }

    private function buildInviteRank($limit)
    {
        $stats = User::select(['invite_user_id', DB::raw('count(*) as count')])
            ->where('created_at', '>=', $this->startAt)
            ->where('created_at', '<', $this->endAt)
            ->whereNotNull('invite_user_id')
            ->groupBy('invite_user_id')
            ->orderBy('count', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('invite_user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['invite_user_id']])) continue;
            $stats[$k]['email'] = $users[$v['invite_user_id']]['email'];
        }
        return $stats;
    }

    private function buildUserConsumptionRank($limit)
    {
        $stats = StatUser::select([
            'user_id',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['user_id']])) continue;
            $stats[$k]['email'] = $users[$v['user_id']]['email'];
        }
        return $stats;
    }

    private function buildServerTrafficRank($limit)
    {
        return StatServer::select([
            'server_id',
            'server_type',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }
}
