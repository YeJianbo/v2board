<?php

namespace Tests\Unit;

use App\Models\Machine;
use PHPUnit\Framework\TestCase;

class MachineTrafficPeriodTest extends TestCase
{
    public function test_daily_period_uses_configured_reset_time(): void
    {
        $machine = $this->machine([
            'traffic_reset_mode' => 'daily',
            'traffic_reset_hour' => 3,
            'traffic_reset_minute' => 30,
        ]);
        $at = strtotime('2026-08-30 02:00:00 UTC');

        $period = $machine->resolveTrafficPeriod($at, null, new \DateTimeZone('UTC'));

        $this->assertSame(strtotime('2026-08-29 03:30:00 UTC'), $period['started_at']);
        $this->assertSame(strtotime('2026-08-30 03:30:00 UTC'), $period['ends_at']);
    }

    public function test_monthly_period_uses_previous_month_before_reset_day(): void
    {
        $machine = $this->machine([
            'traffic_reset_mode' => 'monthly',
            'traffic_reset_day' => 15,
            'traffic_reset_hour' => 4,
            'traffic_reset_minute' => 0,
        ]);
        $at = strtotime('2026-08-10 12:00:00 UTC');

        $period = $machine->resolveTrafficPeriod($at, null, new \DateTimeZone('UTC'));

        $this->assertSame(strtotime('2026-07-15 04:00:00 UTC'), $period['started_at']);
        $this->assertSame(strtotime('2026-08-15 04:00:00 UTC'), $period['ends_at']);
    }

    public function test_rolling_period_keeps_the_active_window(): void
    {
        $machine = $this->machine([
            'traffic_reset_mode' => 'rolling',
            'traffic_alert_window' => 86400,
        ]);
        $at = strtotime('2026-08-30 12:00:00 UTC');
        $current = [
            'cycle_signature' => $machine->trafficPeriodSignature(),
            'started_at' => $at - 3600,
            'ends_at' => $at + 82800,
        ];

        $period = $machine->resolveTrafficPeriod($at, $current, new \DateTimeZone('UTC'));

        $this->assertSame($current['started_at'], $period['started_at']);
        $this->assertSame($current['ends_at'], $period['ends_at']);
    }

    private function machine(array $attributes): Machine
    {
        $machine = new Machine();
        $machine->setRawAttributes(array_merge([
            'traffic_alert_window' => 86400,
            'traffic_reset_mode' => 'rolling',
            'traffic_reset_day' => 1,
            'traffic_reset_hour' => 0,
            'traffic_reset_minute' => 0,
        ], $attributes));

        return $machine;
    }
}
