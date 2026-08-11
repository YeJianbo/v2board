<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TelegramNotificationService
{
    private const MACHINE_STATE_PREFIX = 'telegram:machine:monitor:v1:';
    private const USER_TRAFFIC_STATE_PREFIX = 'telegram:user:traffic:v1:';

    private TelegramService $telegramService;

    public function __construct(?TelegramService $telegramService = null)
    {
        $this->telegramService = $telegramService ?: new TelegramService();
    }

    public function checkMachines(bool $dryRun = false): array
    {
        $summary = [
            'machines' => 0,
            'online' => 0,
            'offline' => 0,
            'alerts' => 0,
            'recoveries' => 0,
        ];

        if (!$this->telegramEnabled() || !(bool) $this->setting('telegram_machine_alert_enable', 1)) {
            return $summary;
        }

        $now = time();
        $offlineAfter = $this->clamp((int) $this->setting('telegram_machine_offline_seconds', 300), 180, 86400);
        $statusAlertEnabled = (bool) $this->setting('telegram_machine_status_alert_enable', 1);
        $machines = Machine::query()
            ->get(['id', 'name', 'host', 'status', 'updated_at']);

        foreach ($machines as $machine) {
            $status = $this->machineStatus($machine);
            $reportedAt = (int) ($status['reported_at'] ?? 0);
            if ($reportedAt <= 0) {
                continue;
            }

            $summary['machines']++;
            $online = ($now - $reportedAt) < $offlineAfter;
            $summary[$online ? 'online' : 'offline']++;

            $stateKey = self::MACHINE_STATE_PREFIX . $machine->id;
            $state = $this->cache()->get($stateKey);
            if (!is_array($state) || !array_key_exists('online', $state)) {
                if (!$dryRun) {
                    $this->cache()->forever($stateKey, $this->initialMachineState($online, $now));
                }
                continue;
            }

            if ($online !== (bool) $state['online']) {
                if ($online) {
                    $shouldNotify = $statusAlertEnabled && (bool) ($state['offline_notified'] ?? false);
                    if ($shouldNotify && $this->sendMachineRecovery($machine, $status, $state, $reportedAt, $dryRun)) {
                        $summary['recoveries']++;
                    }
                    $state['online'] = true;
                    $state['offline_since'] = 0;
                    $state['offline_notified'] = false;
                    $state['resources'] = [];
                } else {
                    $notified = false;
                    if ($statusAlertEnabled && $this->sendMachineOffline($machine, $status, $reportedAt, $offlineAfter, $dryRun)) {
                        $summary['alerts']++;
                        $notified = true;
                    }
                    $state['online'] = false;
                    $state['offline_since'] = $reportedAt + $offlineAfter;
                    $state['offline_notified'] = $notified;
                    $state['resources'] = [];
                }
            }

            if ($online && (bool) $state['online']) {
                [$state, $alerts, $recoveries] = $this->checkMachineResources($machine, $status, $state, $now, $dryRun);
                $summary['alerts'] += $alerts;
                $summary['recoveries'] += $recoveries;
            }

            $state['last_checked_at'] = $now;
            if (!$dryRun) {
                $this->cache()->forever($stateKey, $state);
            }
        }

        return $summary;
    }

    public function remindUserTraffic(User $user): bool
    {
        if (
            !$this->telegramEnabled()
            || !(bool) $this->setting('telegram_user_traffic_alert_enable', 1)
            || !$user->remind_traffic
            || !$user->telegram_id
        ) {
            return false;
        }

        if ($user->expired_at !== null && (int) $user->expired_at < time()) {
            return false;
        }

        $total = (int) $user->transfer_enable;
        $used = max(0, (int) $user->u + (int) $user->d);
        $stateKey = self::USER_TRAFFIC_STATE_PREFIX . $user->id;
        if ($total <= 0) {
            $this->cache()->forget($stateKey);
            return false;
        }

        $percentage = ($used / $total) * 100;
        $threshold = $this->clamp((int) $this->setting('telegram_user_traffic_threshold', 95), 50, 100);
        $level = $percentage >= 100 ? 'exhausted' : ($percentage >= $threshold ? 'warning' : 'normal');
        $previousLevel = (string) $this->cache()->get($stateKey, 'normal');

        if ($level === 'normal') {
            if ($previousLevel !== 'normal') {
                $this->cache()->forever($stateKey, 'normal');
            }
            return false;
        }

        if ($level === $previousLevel) {
            return false;
        }

        $remaining = max(0, $total - $used);
        $title = $level === 'exhausted' ? '流量已耗尽' : '流量使用提醒';
        $message = implode("\n", [
            ($level === 'exhausted' ? '🔴 ' : '🟠 ') . $title,
            '已使用：' . $this->formatBytes($used) . ' / ' . $this->formatBytes($total),
            '使用比例：' . number_format(min($percentage, 999.99), 2) . '%',
            '剩余流量：' . $this->formatBytes($remaining),
            $level === 'exhausted' ? '请及时续费或购买流量包。' : '请留意剩余流量，避免服务中断。',
        ]);

        SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
        $this->cache()->forever($stateKey, $level);
        return true;
    }

    private function checkMachineResources(Machine $machine, array $status, array $state, int $now, bool $dryRun): array
    {
        if (!(bool) $this->setting('telegram_machine_resource_alert_enable', 1)) {
            $state['resources'] = [];
            return [$state, 0, 0];
        }

        $duration = $this->clamp((int) $this->setting('telegram_machine_resource_duration_seconds', 180), 60, 86400);
        $metrics = [];
        if ((bool) $this->setting('telegram_machine_cpu_alert_enable', 1)) {
            $metrics['cpu'] = [
                'label' => 'CPU',
                'value' => $this->numericStatusValue($status, 'cpu'),
                'threshold' => $this->clamp((int) $this->setting('telegram_machine_cpu_threshold', 90), 1, 100),
                'suffix' => '%',
                'duration' => $duration,
            ];
        }
        if ((bool) $this->setting('telegram_machine_memory_alert_enable', 1)) {
            $metrics['mem'] = [
                'label' => '内存',
                'value' => $this->numericStatusValue($status, 'mem'),
                'threshold' => $this->clamp((int) $this->setting('telegram_machine_memory_threshold', 90), 1, 100),
                'suffix' => '%',
                'duration' => $duration,
            ];
        }
        if ((bool) $this->setting('telegram_machine_disk_alert_enable', 1)) {
            $metrics['disk'] = [
                'label' => '磁盘',
                'value' => $this->numericStatusValue($status, 'disk'),
                'threshold' => $this->clamp((int) $this->setting('telegram_machine_disk_threshold', 95), 1, 100),
                'suffix' => '%',
                'duration' => $duration,
            ];
        }

        $networkThreshold = max(0, (float) $this->setting('telegram_machine_network_mbps_threshold', 0));
        if ((bool) $this->setting('telegram_machine_network_alert_enable', 0) && $networkThreshold > 0) {
            $networkMbps = max(
                (float) ($status['net_in_rate'] ?? 0),
                (float) ($status['net_out_rate'] ?? 0)
            ) * 8 / 1000000;
            $metrics['network'] = [
                'label' => '瞬时流量',
                'value' => round($networkMbps, 2),
                'threshold' => $networkThreshold,
                'suffix' => ' Mbps',
                'duration' => $duration,
            ];
        }

        $resourceState = is_array($state['resources'] ?? null) ? $state['resources'] : [];
        foreach (['cpu', 'mem', 'disk', 'network'] as $key) {
            if (!isset($metrics[$key])) {
                unset($resourceState[$key]);
            }
        }
        $newAlerts = [];
        $newRecoveries = [];

        foreach ($metrics as $key => $metric) {
            if ($metric['value'] === null) {
                continue;
            }

            $current = is_array($resourceState[$key] ?? null)
                ? $resourceState[$key]
                : ['high_since' => 0, 'alerted' => false];
            $isHigh = $metric['value'] >= $metric['threshold'];
            $isRecovered = $metric['value'] < max(0, $metric['threshold'] - 5);

            if ($isHigh) {
                if ((int) ($current['high_since'] ?? 0) <= 0) {
                    $current['high_since'] = $now;
                }
                if (
                    !(bool) ($current['alerted'] ?? false)
                    && ($now - (int) $current['high_since']) >= $duration
                ) {
                    $newAlerts[$key] = $metric;
                }
            } elseif ($isRecovered) {
                $current['high_since'] = 0;
                if ((bool) ($current['alerted'] ?? false)) {
                    $newRecoveries[$key] = $metric;
                }
            }

            $resourceState[$key] = $current;
        }

        $alertCount = 0;
        $recoveryCount = 0;
        if ($newAlerts && $this->sendResourceMessage($machine, $status, $newAlerts, false, $dryRun)) {
            $alertCount++;
            foreach (array_keys($newAlerts) as $key) {
                $resourceState[$key]['alerted'] = true;
            }
        }
        if ($newRecoveries && $this->sendResourceMessage($machine, $status, $newRecoveries, true, $dryRun)) {
            $recoveryCount++;
            foreach (array_keys($newRecoveries) as $key) {
                $resourceState[$key]['alerted'] = false;
            }
        }

        $state['resources'] = $resourceState;
        return [$state, $alertCount, $recoveryCount];
    }

    private function sendMachineOffline(Machine $machine, array $status, int $reportedAt, int $offlineAfter, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $message = implode("\n", [
            '🔴 主机离线',
            '名称：' . $machine->name,
            '地址：' . $this->machineAddress($machine, $status),
            '最后上报：' . date('Y-m-d H:i:s', $reportedAt),
            '离线时长：' . $this->formatDuration(max(0, time() - $reportedAt)),
            '判定阈值：' . $this->formatDuration($offlineAfter),
        ]);

        return $this->telegramService->sendMessageWithAdmin($message, false, '') > 0;
    }

    private function sendMachineRecovery(Machine $machine, array $status, array $state, int $reportedAt, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $offlineSince = (int) ($state['offline_since'] ?? 0);
        $message = implode("\n", [
            '🟢 主机恢复在线',
            '名称：' . $machine->name,
            '地址：' . $this->machineAddress($machine, $status),
            '恢复时间：' . date('Y-m-d H:i:s', $reportedAt),
            '中断时长：' . $this->formatDuration($offlineSince > 0 ? max(0, $reportedAt - $offlineSince) : 0),
        ]);

        return $this->telegramService->sendMessageWithAdmin($message, false, '') > 0;
    }

    private function sendResourceMessage(Machine $machine, array $status, array $metrics, bool $recovered, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $lines = [
            ($recovered ? '🟢 主机资源恢复' : '🟠 主机资源告警'),
            '名称：' . $machine->name,
            '地址：' . $this->machineAddress($machine, $status),
        ];
        foreach ($metrics as $metric) {
            $lines[] = sprintf(
                '%s：%s%s（阈值 %s%s）',
                $metric['label'],
                number_format((float) $metric['value'], 2),
                $metric['suffix'],
                number_format((float) $metric['threshold'], 2),
                $metric['suffix']
            );
        }
        if (!$recovered) {
            $firstMetric = reset($metrics);
            if (is_array($firstMetric) && !empty($firstMetric['duration'])) {
                $lines[] = '持续时间：' . $this->formatDuration((int) $firstMetric['duration']);
            }
        }

        return $this->telegramService->sendMessageWithAdmin(implode("\n", $lines), false, '') > 0;
    }

    private function initialMachineState(bool $online, int $now): array
    {
        return [
            'online' => $online,
            'offline_since' => 0,
            'offline_notified' => false,
            'resources' => [],
            'initialized_at' => $now,
            'last_checked_at' => $now,
        ];
    }

    private function machineStatus(Machine $machine): array
    {
        $cached = Machine::probeCache()->get($machine->statusCacheKey());
        if (is_array($cached)) {
            return $cached;
        }
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        $decoded = json_decode((string) $machine->status, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }

    private function machineAddress(Machine $machine, array $status): string
    {
        foreach (['ddns_host', 'primary_ip', 'public_ipv4', 'public_ipv6', 'remote_ip', 'ip'] as $key) {
            $value = trim((string) ($status[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) $machine->host) ?: '-';
    }

    private function numericStatusValue(array $status, string $key): ?float
    {
        return array_key_exists($key, $status) && is_numeric($status[$key])
            ? (float) $status[$key]
            : null;
    }

    private function telegramEnabled(): bool
    {
        return (bool) $this->setting('telegram_bot_enable', 0)
            && trim((string) $this->setting('telegram_bot_token', '')) !== '';
    }

    private function setting(string $key, $default = null)
    {
        return function_exists('admin_setting')
            ? admin_setting($key, config('v2board.' . $key, $default))
            : config('v2board.' . $key, $default);
    }

    private function cache()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store(config('cache.default', 'file'));
        }
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . '秒';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . '分钟';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . '小时' . floor(($seconds % 3600) / 60) . '分钟';
        }

        return floor($seconds / 86400) . '天' . floor(($seconds % 86400) / 3600) . '小时';
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }
}
