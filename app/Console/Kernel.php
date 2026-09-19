<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // traffic
        $schedule->command('traffic:update')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        // v2board
        $schedule->command('v2board:statistics')->dailyAt('0:10')->onOneServer();
        // check
        $schedule->command('check:order')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:commission')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:ticket')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:telegram-notifications')->everyMinute()->onOneServer()->withoutOverlapping(5)->runInBackground();
        $schedule->command('check:renewal')->dailyAt('22:30')->onOneServer()->withoutOverlapping(10);
        // reset
        $schedule->command('reset:traffic')->daily()->onOneServer()->withoutOverlapping(10);
        $schedule->command('reset:log')->daily()->onOneServer();
        $schedule->command('model-relay:maintain')->everyTenMinutes()->onOneServer()->withoutOverlapping(10);
        $schedule->command('model-relay:quality')->everyMinute()->onOneServer()->withoutOverlapping(15)->runInBackground();
        $schedule->command('machine:aggregate-metrics --hours=2')
            ->hourlyAt(5)
            ->onOneServer()
            ->withoutOverlapping(30);
        $schedule->command('machine:prune-metrics --minute-days=7 --hour-days=30 --batch=5000')
            ->dailyAt('03:15')
            ->onOneServer()
            ->withoutOverlapping(30);
        $schedule->command('traffic:prune-statistics --minute-days=31 --hour-days=366 --daily-days=1095 --batch=5000')
            ->dailyAt('04:10')
            ->onOneServer()
            ->withoutOverlapping(30);
        // send
        $schedule->command('send:remindMail')->dailyAt('11:30')->onOneServer()->withoutOverlapping(30);
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        // backup (default 03:30 to avoid traffic reset and statistics jobs around midnight)
        if ((bool) admin_setting('backup_enable', false)) {
            $backupTime = (string) admin_setting('backup_time', '03:30');
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $backupTime)) {
                $backupTime = '03:30';
            }
            $backupCommand = $schedule->command('panel:backup')
                ->onOneServer()
                ->withoutOverlapping(360)
                ->runInBackground();
            if (admin_setting('backup_frequency', 'daily') === 'weekly') {
                $backupCommand->weeklyOn(1, $backupTime);
            } else {
                $backupCommand->dailyAt($backupTime);
            }
        }

        app(PluginManager::class)->registerPluginSchedules($schedule);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Throwable $exception) {
            // 数据库尚未安装或暂不可用时，基础 Artisan 命令仍应可以启动。
        }

        require base_path('routes/console.php');
    }
}
