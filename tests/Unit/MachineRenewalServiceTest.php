<?php

namespace Tests\Unit;

use App\Services\MachineRenewalService;
use PHPUnit\Framework\TestCase;

class MachineRenewalServiceTest extends TestCase
{
    private MachineRenewalService $service;
    private \DateTimeZone $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MachineRenewalService();
        $this->timezone = new \DateTimeZone('UTC');
    }

    public function test_month_renewal_uses_calendar_month_without_overflow(): void
    {
        $result = $this->service->extend(
            strtotime('2026-01-31 00:00:00 UTC'),
            'month',
            1,
            strtotime('2026-01-10 00:00:00 UTC'),
            $this->timezone
        );

        $this->assertSame(strtotime('2026-01-31 00:00:00 UTC'), $result['base_at']);
        $this->assertSame(strtotime('2026-02-28 00:00:00 UTC'), $result['renew_at']);
    }

    public function test_repeated_month_end_renewal_keeps_month_end(): void
    {
        $result = $this->service->extend(
            strtotime('2026-02-28 00:00:00 UTC'),
            'month',
            1,
            strtotime('2026-02-10 00:00:00 UTC'),
            $this->timezone
        );

        $this->assertSame(strtotime('2026-03-31 00:00:00 UTC'), $result['renew_at']);
    }

    public function test_expired_machine_renews_from_original_expiry(): void
    {
        $now = strtotime('2026-09-02 12:00:00 UTC');
        $result = $this->service->extend(
            strtotime('2026-08-01 00:00:00 UTC'),
            'day',
            7,
            $now,
            $this->timezone
        );

        $this->assertSame(strtotime('2026-08-01 00:00:00 UTC'), $result['base_at']);
        $this->assertSame(strtotime('2026-08-08 00:00:00 UTC'), $result['renew_at']);
    }

    public function test_machine_without_expiry_renews_from_now(): void
    {
        $now = strtotime('2026-09-02 12:00:00 UTC');
        $result = $this->service->extend(0, 'month', 1, $now, $this->timezone);

        $this->assertSame($now, $result['base_at']);
        $this->assertSame(strtotime('2026-10-02 12:00:00 UTC'), $result['renew_at']);
    }

    public function test_explicit_date_must_extend_the_current_base(): void
    {
        $current = strtotime('2026-10-01 00:00:00 UTC');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->useTargetDate(
            $current,
            strtotime('2026-09-30 00:00:00 UTC'),
            strtotime('2026-09-02 00:00:00 UTC')
        );
    }
}
