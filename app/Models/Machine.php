<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Machine extends Model
{
    public const ADMIN_GENERATED_RELAY_RULES_CACHE_KEY = 'admin:machine:generated_relay_rules:v1';

    protected $table = 'v2_machine';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'ddns_enabled' => 'boolean',
        'ddns_proxied' => 'boolean',
        'probe_auto_update' => 'boolean',
        'relay_rules' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public static function statusCacheKeyForId(int $machineId): string
    {
        return 'machine:status:' . $machineId;
    }

    public function statusCacheKey(): string
    {
        return self::statusCacheKeyForId((int) $this->getKey());
    }

    public static function probeAuthCacheKeyForId(int $machineId): string
    {
        return 'machine:probe_auth:' . $machineId;
    }

    public function probeAuthCacheKey(): string
    {
        return self::probeAuthCacheKeyForId((int) $this->getKey());
    }

    public static function probeCache()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store(config('cache.default', 'file'));
        }
    }

    public static function forgetAdminFetchCache(): void
    {
        self::probeCache()->forget(self::ADMIN_GENERATED_RELAY_RULES_CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(function (Machine $machine) {
            self::probeCache()->forget($machine->probeAuthCacheKey());
            self::forgetAdminFetchCache();
        });

        static::deleted(function (Machine $machine) {
            self::probeCache()->forget($machine->probeAuthCacheKey());
            self::probeCache()->forget($machine->statusCacheKey());
            self::forgetAdminFetchCache();
        });
    }
}
