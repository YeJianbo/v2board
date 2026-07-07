<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficResetLog extends Model
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_FIRST_DAY_MONTH = 'first_day_month';
    public const TYPE_MONTHLY = 'monthly';
    public const TYPE_FIRST_DAY_YEAR = 'first_day_year';
    public const TYPE_YEARLY = 'yearly';

    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CRON = 'cron';
    public const SOURCE_GIFT_CARD = 'gift_card';

    protected $table = 'v2_traffic_reset_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'metadata' => 'array',
        'reset_time' => 'datetime',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getResetTypeNames(): array
    {
        return [
            self::TYPE_MANUAL => '手动重置',
            self::TYPE_FIRST_DAY_MONTH => '每月首日',
            self::TYPE_MONTHLY => '按月重置',
            self::TYPE_FIRST_DAY_YEAR => '每年首日',
            self::TYPE_YEARLY => '按年重置',
        ];
    }

    public static function getSourceNames(): array
    {
        return [
            self::SOURCE_AUTO => '自动',
            self::SOURCE_MANUAL => '手动',
            self::SOURCE_CRON => '计划任务',
            self::SOURCE_GIFT_CARD => '礼品卡',
        ];
    }

    public function getResetTypeName(): string
    {
        return self::getResetTypeNames()[$this->reset_type] ?? (string) $this->reset_type;
    }

    public function getSourceName(): string
    {
        return self::getSourceNames()[$this->trigger_source] ?? (string) $this->trigger_source;
    }

    public function formatTraffic(int|float|null $bytes): string
    {
        $bytes = max(0, (float) ($bytes ?? 0));
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }
}
