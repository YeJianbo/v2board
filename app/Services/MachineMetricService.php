<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MachineMetric;
use App\Models\MachineMetricHourly;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineMetricService
{
    public const SAMPLE_INTERVAL_SECONDS = 60;
    public const RETENTION_DAYS = 7;
    public const HOURLY_RETENTION_DAYS = 30;
    public const HOUR_SECONDS = 3600;

    private const STORAGE_STATS_CACHE_KEY = 'machine_metric_storage_stats:v1';
    private const STORAGE_STATS_CACHE_SECONDS = 300;

    public function record(Machine $machine, array $status, ?int $timestamp = null): bool
    {
        $timestamp = $timestamp ?: time();
        $recordedAt = intdiv($timestamp, self::SAMPLE_INTERVAL_SECONDS) * self::SAMPLE_INTERVAL_SECONDS;
        $machineId = (int) $machine->id;
        $sample = $this->buildSample($machine, $status, $recordedAt);
        if ($this->claimMinuteBucket($machineId, $recordedAt)) {
            $inserted = MachineMetric::query()->insertOrIgnore($sample) > 0;
            $this->rememberMinuteNetworkPeaks($machineId, $recordedAt, $sample);
            if ($inserted) {
                return true;
            }
        }

        return $this->updateMinuteNetworkPeaks($machineId, $recordedAt, $sample);
    }

    public function buildSample(Machine $machine, array $status, int $recordedAt): array
    {
        $memTotal = $this->unsignedValue($status['mem_total'] ?? 0);
        $memUsed = $this->unsignedValue($status['mem_used'] ?? 0);
        $diskTotal = $this->unsignedValue($status['disk_total'] ?? 0);
        $diskUsed = $this->unsignedValue($status['disk_used'] ?? 0);
        $swapTotal = $this->unsignedValue($status['swap_total'] ?? 0);
        $swapUsed = $this->unsignedValue($status['swap_used'] ?? 0);

        return [
            'machine_id' => (int) $machine->id,
            'cpu' => $this->percentage($status['cpu'] ?? null),
            'memory' => $this->percentage($status['mem'] ?? null, $memUsed, $memTotal),
            'disk' => $this->percentage($status['disk'] ?? null, $diskUsed, $diskTotal),
            'swap' => $swapTotal > 0
                ? $this->percentage($status['swap_percent'] ?? null, $swapUsed, $swapTotal)
                : null,
            'load_1' => $this->decimalValue($status['load1'] ?? null, 3),
            'load_5' => $this->decimalValue($status['load5'] ?? null, 3),
            'load_15' => $this->decimalValue($status['load15'] ?? null, 3),
            'net_in_rate' => $this->unsignedValue($status['net_in_sample_peak'] ?? $status['net_in_rate'] ?? 0),
            'net_out_rate' => $this->unsignedValue($status['net_out_sample_peak'] ?? $status['net_out_rate'] ?? 0),
            'mem_total' => $memTotal,
            'mem_used' => $memUsed,
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'swap_total' => $swapTotal,
            'swap_used' => $swapUsed,
            'recorded_at' => $recordedAt,
            'created_at' => time(),
        ];
    }

    public function history(int $machineId, int $startedAt, int $endAt, int $bucketSeconds)
    {
        $minuteCutover = intdiv(
            $endAt - self::RETENTION_DAYS * 86400,
            self::HOUR_SECONDS
        ) * self::HOUR_SECONDS;

        if ($startedAt >= $minuteCutover) {
            return $this->groupedHistory(
                MachineMetric::class,
                $machineId,
                $startedAt,
                $endAt,
                $bucketSeconds
            );
        }

        $hourly = $this->groupedHistory(
            MachineMetricHourly::class,
            $machineId,
            $startedAt,
            $minuteCutover,
            max(self::HOUR_SECONDS, $bucketSeconds),
            true
        );
        $minute = $this->groupedHistory(
            MachineMetric::class,
            $machineId,
            $minuteCutover,
            $endAt,
            $bucketSeconds
        );

        return $hourly
            ->concat($minute)
            ->sortBy('recorded_at')
            ->values();
    }

    public function aggregateHourly(int $hours = 2, ?int $endAt = null): array
    {
        $hours = max(1, min(self::HOURLY_RETENTION_DAYS * 24, $hours));
        $endAt = intdiv($endAt ?: time(), self::HOUR_SECONDS) * self::HOUR_SECONDS;
        $startedAt = $endAt - $hours * self::HOUR_SECONDS;
        $now = time();

        $aggregates = MachineMetric::query()
            ->where('recorded_at', '>=', $startedAt)
            ->where('recorded_at', '<', $endAt)
            ->selectRaw(
                'machine_id, AVG(cpu) AS cpu, AVG(memory) AS memory, AVG(disk) AS disk, AVG(swap) AS swap, '
                . 'AVG(load_1) AS load_1, AVG(load_5) AS load_5, AVG(load_15) AS load_15, '
                . 'MAX(net_in_rate) AS net_in_rate, MAX(net_out_rate) AS net_out_rate, '
                . 'MAX(mem_total) AS mem_total, AVG(mem_used) AS mem_used, '
                . 'MAX(disk_total) AS disk_total, AVG(disk_used) AS disk_used, '
                . 'MAX(swap_total) AS swap_total, AVG(swap_used) AS swap_used, '
                . 'COUNT(*) AS sample_count, FLOOR(recorded_at / ?) AS time_bucket',
                [self::HOUR_SECONDS]
            )
            ->groupBy('machine_id', 'time_bucket')
            ->get();

        $rows = $aggregates->map(function ($record) use ($now) {
            return [
                'machine_id' => (int) $record->machine_id,
                'cpu' => $this->nullableFloat($record->cpu, 2),
                'memory' => $this->nullableFloat($record->memory, 2),
                'disk' => $this->nullableFloat($record->disk, 2),
                'swap' => $this->nullableFloat($record->swap, 2),
                'load_1' => $this->nullableFloat($record->load_1, 3),
                'load_5' => $this->nullableFloat($record->load_5, 3),
                'load_15' => $this->nullableFloat($record->load_15, 3),
                'net_in_rate' => $this->unsignedValue($record->net_in_rate),
                'net_out_rate' => $this->unsignedValue($record->net_out_rate),
                'mem_total' => $this->unsignedValue($record->mem_total),
                'mem_used' => $this->unsignedValue($record->mem_used),
                'disk_total' => $this->unsignedValue($record->disk_total),
                'disk_used' => $this->unsignedValue($record->disk_used),
                'swap_total' => $this->unsignedValue($record->swap_total),
                'swap_used' => $this->unsignedValue($record->swap_used),
                'sample_count' => (int) $record->sample_count,
                'recorded_at' => (int) $record->time_bucket * self::HOUR_SECONDS,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('v2_machine_metric_hour')->upsert(
                $chunk->all(),
                ['machine_id', 'recorded_at'],
                [
                    'cpu', 'memory', 'disk', 'swap',
                    'load_1', 'load_5', 'load_15',
                    'net_in_rate', 'net_out_rate',
                    'mem_total', 'mem_used', 'disk_total', 'disk_used',
                    'swap_total', 'swap_used', 'sample_count', 'updated_at',
                ]
            );
        }

        if ($rows->isNotEmpty()) {
            $this->forgetStorageStats();
        }

        return [
            'rows' => $rows->count(),
            'started_at' => $startedAt,
            'end_at' => $endAt,
        ];
    }

    public function buildAnnotations(
        $records,
        int $bucketSeconds,
        array $configuration,
        ?int $endAt = null
    ): array {
        $bucketSeconds = max(self::SAMPLE_INTERVAL_SECONDS, $bucketSeconds);
        $points = collect($records)->map(function ($record) {
            return [
                'timestamp' => (int) $this->recordValue($record, 'recorded_at'),
                'cpu' => $this->nullableFloat($this->recordValue($record, 'cpu'), 2),
                'memory' => $this->nullableFloat($this->recordValue($record, 'memory'), 2),
                'disk' => $this->nullableFloat($this->recordValue($record, 'disk'), 2),
                'network' => max(
                    0,
                    (float) $this->recordValue($record, 'net_in_rate'),
                    (float) $this->recordValue($record, 'net_out_rate')
                ),
            ];
        })->filter(function (array $point) {
            return $point['timestamp'] > 0;
        })->sortBy('timestamp')->values()->all();

        if (!$points) {
            return [];
        }

        $annotations = [];
        $offlineSeconds = max(
            180,
            (int) ($configuration['offline_seconds'] ?? 300),
            (int) ceil($bucketSeconds * 1.5)
        );
        $this->appendOfflineAnnotations($annotations, $points, $bucketSeconds, $offlineSeconds, $endAt);

        if ((bool) ($configuration['resource_enabled'] ?? true)) {
            $duration = max(60, (int) ($configuration['resource_duration_seconds'] ?? 180));
            foreach (($configuration['metrics'] ?? []) as $field => $metric) {
                if (!(bool) ($metric['enabled'] ?? false)) {
                    continue;
                }
                $threshold = max(0, (float) ($metric['threshold'] ?? 0));
                if ($threshold <= 0) {
                    continue;
                }
                $this->appendThresholdAnnotations(
                    $annotations,
                    $points,
                    $field,
                    (string) ($metric['label'] ?? $field),
                    (string) ($metric['category'] ?? 'usage'),
                    (string) ($metric['color'] ?? '#e6a23c'),
                    $threshold,
                    $duration,
                    $bucketSeconds,
                    $offlineSeconds,
                    (string) ($metric['unit'] ?? '%')
                );
            }
        }

        if ((bool) ($configuration['dynamic_network_spikes'] ?? false)) {
            $this->appendDynamicNetworkSpikes($annotations, $points, $bucketSeconds, $offlineSeconds);
        }

        usort($annotations, function (array $left, array $right) {
            return $left['timestamp'] <=> $right['timestamp'];
        });

        return array_slice($annotations, -200);
    }

    public function bucketSeconds(int $rangeSeconds): int
    {
        return match (true) {
            $rangeSeconds <= 3600 => 60,
            $rangeSeconds <= 21600 => 180,
            $rangeSeconds <= 86400 => 600,
            default => 3600,
        };
    }

    public function storageStats(): array
    {
        $loader = function () {
            try {
                return $this->loadStorageStats();
            } catch (\Throwable $e) {
                return [
                    'available' => false,
                    'minute' => $this->emptyTableStats('v2_machine_metric_minute'),
                    'hourly' => $this->emptyTableStats('v2_machine_metric_hour'),
                    'total_bytes' => 0,
                ];
            }
        };

        try {
            return Cache::store('redis')->remember(
                self::STORAGE_STATS_CACHE_KEY,
                self::STORAGE_STATS_CACHE_SECONDS,
                $loader
            );
        } catch (\Throwable $e) {
            return Cache::store('file')->remember(
                self::STORAGE_STATS_CACHE_KEY,
                self::STORAGE_STATS_CACHE_SECONDS,
                $loader
            );
        }
    }

    public function prune(?int $retentionDays = null, int $batchSize = 5000): int
    {
        return $this->pruneModel(
            MachineMetric::class,
            $retentionDays ?: self::RETENTION_DAYS,
            $batchSize
        );
    }

    public function pruneHourly(?int $retentionDays = null, int $batchSize = 5000): int
    {
        return $this->pruneModel(
            MachineMetricHourly::class,
            $retentionDays ?: self::HOURLY_RETENTION_DAYS,
            $batchSize
        );
    }

    private function groupedHistory(
        string $modelClass,
        int $machineId,
        int $startedAt,
        int $endAt,
        int $bucketSeconds,
        bool $exclusiveEnd = false
    ) {
        $query = $modelClass::query()
            ->where('machine_id', $machineId)
            ->where('recorded_at', '>=', $startedAt);
        $query->where('recorded_at', $exclusiveEnd ? '<' : '<=', $endAt);

        return $query
            ->selectRaw(
                'AVG(cpu) AS cpu, AVG(memory) AS memory, AVG(disk) AS disk, AVG(swap) AS swap, '
                . 'AVG(load_1) AS load_1, AVG(load_5) AS load_5, AVG(load_15) AS load_15, '
                . 'MAX(net_in_rate) AS net_in_rate, MAX(net_out_rate) AS net_out_rate, '
                . 'MAX(mem_total) AS mem_total, AVG(mem_used) AS mem_used, '
                . 'MAX(disk_total) AS disk_total, AVG(disk_used) AS disk_used, '
                . 'MAX(swap_total) AS swap_total, AVG(swap_used) AS swap_used, '
                . 'FLOOR(recorded_at / ?) AS time_bucket',
                [$bucketSeconds]
            )
            ->groupBy('time_bucket')
            ->orderBy('time_bucket')
            ->get()
            ->map(function ($record) use ($bucketSeconds) {
                $record->recorded_at = (int) $record->time_bucket * $bucketSeconds;
                unset($record->time_bucket);
                return $record;
            });
    }

    private function appendOfflineAnnotations(
        array &$annotations,
        array $points,
        int $bucketSeconds,
        int $offlineSeconds,
        ?int $endAt
    ): void {
        $previous = null;
        foreach ($points as $point) {
            if ($previous && ($point['timestamp'] - $previous['timestamp']) > $offlineSeconds) {
                $startedAt = $previous['timestamp'] + $bucketSeconds;
                $finishedAt = max($startedAt, $point['timestamp'] - $bucketSeconds);
                $annotations[] = [
                    'key' => 'offline:' . $startedAt,
                    'type' => 'offline',
                    'category' => 'all',
                    'label' => '离线 / 未上报',
                    'details' => '持续约 ' . $this->formatDuration(max(0, $finishedAt - $startedAt + $bucketSeconds)),
                    'timestamp' => $startedAt,
                    'end_at' => $finishedAt,
                    'color' => '#909399',
                ];
            }
            $previous = $point;
        }

        $lastTimestamp = (int) end($points)['timestamp'];
        if ($endAt && ($endAt - $lastTimestamp) > $offlineSeconds) {
            $startedAt = $lastTimestamp + $bucketSeconds;
            $annotations[] = [
                'key' => 'offline:' . $startedAt,
                'type' => 'offline',
                'category' => 'all',
                'label' => '离线 / 未上报',
                'details' => '截至现在约 ' . $this->formatDuration(max(0, $endAt - $startedAt)),
                'timestamp' => $startedAt,
                'end_at' => $endAt,
                'color' => '#909399',
            ];
        }
    }

    private function appendThresholdAnnotations(
        array &$annotations,
        array $points,
        string $field,
        string $label,
        string $category,
        string $color,
        float $threshold,
        int $duration,
        int $bucketSeconds,
        int $offlineSeconds,
        string $unit
    ): void {
        $episode = null;
        $previousTimestamp = null;
        $finishEpisode = function () use (
            &$annotations,
            &$episode,
            $field,
            $label,
            $category,
            $color,
            $threshold,
            $duration,
            $bucketSeconds,
            $unit
        ) {
            if (!$episode || ($episode['end'] - $episode['start'] + $bucketSeconds) < $duration) {
                $episode = null;
                return;
            }
            $annotations[] = [
                'key' => 'threshold:' . $field . ':' . $episode['start'],
                'type' => 'threshold-' . $field,
                'category' => $category,
                'label' => $label === '流量突增' ? $label : $label . '持续超限',
                'details' => sprintf(
                    '峰值 %s，阈值 %s，持续约 %s',
                    $this->formatMetricValue($episode['max'], $unit),
                    $this->formatMetricValue($threshold, $unit),
                    $this->formatDuration($episode['end'] - $episode['start'] + $bucketSeconds)
                ),
                'timestamp' => $episode['start'],
                'end_at' => $episode['end'] + $bucketSeconds,
                'color' => $color,
            ];
            $episode = null;
        };

        foreach ($points as $point) {
            $timestamp = (int) $point['timestamp'];
            if ($previousTimestamp !== null && ($timestamp - $previousTimestamp) > $offlineSeconds) {
                $finishEpisode();
            }
            $value = $point[$field] ?? null;
            if ($value !== null && $value >= $threshold) {
                if (!$episode) {
                    $episode = ['start' => $timestamp, 'end' => $timestamp, 'max' => (float) $value];
                } else {
                    $episode['end'] = $timestamp;
                    $episode['max'] = max($episode['max'], (float) $value);
                }
            } else {
                $finishEpisode();
            }
            $previousTimestamp = $timestamp;
        }
        $finishEpisode();
    }

    private function appendDynamicNetworkSpikes(
        array &$annotations,
        array $points,
        int $bucketSeconds,
        int $offlineSeconds
    ): void {
        if (count($points) < 8) {
            return;
        }
        $rates = array_map(function (array $point) {
            return (float) $point['network'];
        }, $points);
        sort($rates, SORT_NUMERIC);
        $middle = intdiv(count($rates), 2);
        $median = count($rates) % 2
            ? $rates[$middle]
            : ($rates[$middle - 1] + $rates[$middle]) / 2;
        $threshold = max(1024 * 1024, $median * 5);

        $this->appendThresholdAnnotations(
            $annotations,
            $points,
            'network',
            '流量突增',
            'network',
            '#f59e0b',
            $threshold,
            $bucketSeconds,
            $bucketSeconds,
            $offlineSeconds,
            'B/s'
        );
    }

    private function loadStorageStats(): array
    {
        $database = DB::connection()->getDatabaseName();
        $tables = [
            'minute' => 'v2_machine_metric_minute',
            'hourly' => 'v2_machine_metric_hour',
        ];
        $tableRows = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->whereIn('TABLE_NAME', array_values($tables))
            ->selectRaw(
                'TABLE_NAME AS table_name, TABLE_ROWS AS estimated_rows, '
                . 'DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes'
            )
            ->get()
            ->keyBy('table_name');

        $result = ['available' => true, 'total_bytes' => 0];
        foreach ($tables as $key => $table) {
            $stats = $this->emptyTableStats($table);
            $tableInfo = $tableRows->get($table);
            if ($tableInfo) {
                $stats['estimated_rows'] = (int) $tableInfo->estimated_rows;
                $stats['data_bytes'] = (int) $tableInfo->data_bytes;
                $stats['index_bytes'] = (int) $tableInfo->index_bytes;
                $stats['total_bytes'] = $stats['data_bytes'] + $stats['index_bytes'];
            }
            if (Schema::hasTable($table)) {
                $bounds = DB::table($table)
                    ->selectRaw('MIN(recorded_at) AS first_recorded_at, MAX(recorded_at) AS last_recorded_at')
                    ->first();
                $stats['first_recorded_at'] = $bounds && $bounds->first_recorded_at !== null
                    ? (int) $bounds->first_recorded_at
                    : null;
                $stats['last_recorded_at'] = $bounds && $bounds->last_recorded_at !== null
                    ? (int) $bounds->last_recorded_at
                    : null;
            }
            $result[$key] = $stats;
            $result['total_bytes'] += $stats['total_bytes'];
        }

        return $result;
    }

    private function emptyTableStats(string $table): array
    {
        return [
            'table' => $table,
            'estimated_rows' => 0,
            'data_bytes' => 0,
            'index_bytes' => 0,
            'total_bytes' => 0,
            'first_recorded_at' => null,
            'last_recorded_at' => null,
        ];
    }

    private function pruneModel(string $modelClass, int $retentionDays, int $batchSize): int
    {
        $retentionDays = max(1, min(90, $retentionDays));
        $batchSize = max(100, min(10000, $batchSize));
        $cutoff = time() - $retentionDays * 86400;
        $deleted = 0;

        do {
            $ids = $modelClass::query()
                ->where('recorded_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
                ->all();
            if (!$ids) {
                break;
            }
            $deleted += $modelClass::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === $batchSize);

        if ($deleted > 0) {
            $this->forgetStorageStats();
        }

        return $deleted;
    }

    private function forgetStorageStats(): void
    {
        foreach (['redis', 'file'] as $store) {
            try {
                Cache::store($store)->forget(self::STORAGE_STATS_CACHE_KEY);
            } catch (\Throwable $e) {
                // The other cache store remains available when Redis is temporarily unavailable.
            }
        }
    }

    private function claimMinuteBucket(int $machineId, int $recordedAt): bool
    {
        $key = 'machine_metric_minute:' . $machineId . ':' . $recordedAt;
        try {
            return Cache::store('redis')->add($key, true, self::SAMPLE_INTERVAL_SECONDS * 2);
        } catch (\Throwable $e) {
            return Cache::store('file')->add(
                $key,
                true,
                self::SAMPLE_INTERVAL_SECONDS * 2
            );
        }
    }

    private function updateMinuteNetworkPeaks(int $machineId, int $recordedAt, array $sample): bool
    {
        $key = $this->minutePeakCacheKey($machineId, $recordedAt);
        $previous = $this->readMinuteNetworkPeaks($key);
        $previous = is_array($previous) ? $previous : ['net_in_rate' => 0, 'net_out_rate' => 0];
        $incomingIn = max(0, (int) ($sample['net_in_rate'] ?? 0));
        $incomingOut = max(0, (int) ($sample['net_out_rate'] ?? 0));
        $updated = 0;

        if ($incomingIn > (int) ($previous['net_in_rate'] ?? 0)) {
            $updated += MachineMetric::query()
                ->where('machine_id', $machineId)
                ->where('recorded_at', $recordedAt)
                ->where('net_in_rate', '<', $incomingIn)
                ->update(['net_in_rate' => $incomingIn]);
        }
        if ($incomingOut > (int) ($previous['net_out_rate'] ?? 0)) {
            $updated += MachineMetric::query()
                ->where('machine_id', $machineId)
                ->where('recorded_at', $recordedAt)
                ->where('net_out_rate', '<', $incomingOut)
                ->update(['net_out_rate' => $incomingOut]);
        }

        $this->writeMinuteNetworkPeaks($key, [
            'net_in_rate' => max($incomingIn, (int) ($previous['net_in_rate'] ?? 0)),
            'net_out_rate' => max($incomingOut, (int) ($previous['net_out_rate'] ?? 0)),
        ]);

        return $updated > 0;
    }

    private function rememberMinuteNetworkPeaks(int $machineId, int $recordedAt, array $sample): void
    {
        $this->writeMinuteNetworkPeaks($this->minutePeakCacheKey($machineId, $recordedAt), [
            'net_in_rate' => max(0, (int) ($sample['net_in_rate'] ?? 0)),
            'net_out_rate' => max(0, (int) ($sample['net_out_rate'] ?? 0)),
        ]);
    }

    private function readMinuteNetworkPeaks(string $key)
    {
        try {
            return Cache::store('redis')->get($key);
        } catch (\Throwable $e) {
            return Cache::store('file')->get($key);
        }
    }

    private function writeMinuteNetworkPeaks(string $key, array $value): void
    {
        try {
            Cache::store('redis')->put($key, $value, self::SAMPLE_INTERVAL_SECONDS * 2);
        } catch (\Throwable $e) {
            Cache::store('file')->put($key, $value, self::SAMPLE_INTERVAL_SECONDS * 2);
        }
    }

    private function minutePeakCacheKey(int $machineId, int $recordedAt): string
    {
        return 'machine_metric_minute_peak:' . $machineId . ':' . $recordedAt;
    }

    private function recordValue($record, string $key)
    {
        if (is_array($record)) {
            return $record[$key] ?? null;
        }

        return $record->{$key} ?? null;
    }

    private function formatMetricValue(float $value, string $unit): string
    {
        if ($unit === 'B/s') {
            return $this->formatRate($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . $unit;
    }

    private function formatRate(float $value): string
    {
        $units = ['B/s', 'KiB/s', 'MiB/s', 'GiB/s'];
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$index];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds >= 86400) {
            return round($seconds / 86400, 1) . ' 天';
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600, 1) . ' 小时';
        }
        if ($seconds >= 60) {
            return max(1, round($seconds / 60)) . ' 分钟';
        }

        return max(0, $seconds) . ' 秒';
    }

    private function percentage($value, int $used = 0, int $total = 0): ?float
    {
        if (is_numeric($value)) {
            return round(max(0, min(100, (float) $value)), 2);
        }
        if ($total <= 0) {
            return null;
        }

        return round(max(0, min(100, $used / $total * 100)), 2);
    }

    private function unsignedValue($value): int
    {
        return is_numeric($value) ? max(0, (int) round((float) $value)) : 0;
    }

    private function decimalValue($value, int $precision): ?float
    {
        return is_numeric($value) ? round(max(0, (float) $value), $precision) : null;
    }

    private function nullableFloat($value, int $precision): ?float
    {
        return is_numeric($value) ? round((float) $value, $precision) : null;
    }
}
