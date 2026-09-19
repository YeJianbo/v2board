<?php

namespace App\Console\Commands;

use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;

class CheckTelegramNotifications extends Command
{
    protected $signature = 'check:telegram-notifications {--dry-run : 只统计状态，不发送通知或更新监控状态}';

    protected $description = '检查机器状态并发送Telegram通知';

    public function handle(TelegramNotificationService $notificationService): int
    {
        $summary = $notificationService->checkMachines((bool) $this->option('dry-run'));
        $this->line(sprintf(
            'machines=%d online=%d offline=%d alerts=%d recoveries=%d renewals=%d',
            $summary['machines'],
            $summary['online'],
            $summary['offline'],
            $summary['alerts'],
            $summary['recoveries'],
            $summary['renewals'] ?? 0
        ));

        return self::SUCCESS;
    }
}
