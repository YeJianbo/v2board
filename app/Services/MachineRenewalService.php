<?php

namespace App\Services;

class MachineRenewalService
{
    public const MAX_RENEW_AT = 2147483647;

    public function extend(
        int $currentRenewAt,
        string $unit,
        int $value,
        ?int $now = null,
        ?\DateTimeZone $timezone = null
    ): array {
        $unit = strtolower(trim($unit));
        $limits = [
            'day' => 3650,
            'month' => 120,
            'year' => 20,
        ];
        if (!isset($limits[$unit]) || $value < 1 || $value > $limits[$unit]) {
            throw new \InvalidArgumentException('续费时长不在允许范围内');
        }

        $baseAt = $this->resolveBaseAt($currentRenewAt, $now);
        $timezone = $timezone ?: new \DateTimeZone(date_default_timezone_get());
        $base = (new \DateTimeImmutable('@' . $baseAt))->setTimezone($timezone);

        if ($unit === 'day') {
            $renewAt = $base->modify('+' . $value . ' days')->getTimestamp();
        } else {
            $months = $unit === 'year' ? $value * 12 : $value;
            $renewAt = $this->addCalendarMonths($base, $months)->getTimestamp();
        }

        if ($renewAt > self::MAX_RENEW_AT) {
            throw new \InvalidArgumentException('续费日期超出系统支持范围');
        }

        return [
            'base_at' => $baseAt,
            'renew_at' => $renewAt,
        ];
    }

    public function useTargetDate(int $currentRenewAt, int $targetAt, ?int $now = null): array
    {
        $baseAt = $this->resolveBaseAt($currentRenewAt, $now);
        if ($targetAt <= $baseAt || $targetAt > self::MAX_RENEW_AT) {
            throw new \InvalidArgumentException('新的到期日期必须晚于当前续费基准日期');
        }

        return [
            'base_at' => $baseAt,
            'renew_at' => $targetAt,
        ];
    }

    private function resolveBaseAt(int $currentRenewAt, ?int $now): int
    {
        return $currentRenewAt > 0
            ? $currentRenewAt
            : ($now ?: time());
    }

    private function addCalendarMonths(\DateTimeImmutable $base, int $months): \DateTimeImmutable
    {
        $sourceDay = (int) $base->format('j');
        $sourceLastDay = (int) $base->format('t');
        $targetMonth = $base
            ->modify('first day of this month')
            ->modify('+' . $months . ' months');
        $targetLastDay = (int) $targetMonth->format('t');
        $targetDay = $sourceDay === $sourceLastDay
            ? $targetLastDay
            : min($sourceDay, $targetLastDay);

        return $targetMonth
            ->setDate(
                (int) $targetMonth->format('Y'),
                (int) $targetMonth->format('n'),
                $targetDay
            )
            ->setTime(
                (int) $base->format('H'),
                (int) $base->format('i'),
                (int) $base->format('s')
            );
    }
}
