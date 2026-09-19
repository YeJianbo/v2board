<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Machine extends Model
{
    public const DEFAULT_NETWORK_QUALITY_TARGETS = [
        ['key' => 'china_telecom', 'name' => '中国电信', 'host' => 'gd-ct-dualstack.ip.zstaticcdn.com', 'probe_type' => 'icmp', 'port' => 80, 'ip_version' => 'auto', 'enabled' => true],
        ['key' => 'china_unicom', 'name' => '中国联通', 'host' => 'gd-cu-dualstack.ip.zstaticcdn.com', 'probe_type' => 'icmp', 'port' => 80, 'ip_version' => 'auto', 'enabled' => true],
        ['key' => 'china_mobile', 'name' => '中国移动', 'host' => 'gd-cm-dualstack.ip.zstaticcdn.com', 'probe_type' => 'icmp', 'port' => 80, 'ip_version' => 'auto', 'enabled' => true],
        ['key' => 'baidu', 'name' => '百度', 'host' => 'lf3-ips.zstaticcdn.com', 'probe_type' => 'icmp', 'port' => 80, 'ip_version' => 'auto', 'enabled' => true],
    ];
    public const ADMIN_GENERATED_RELAY_RULES_CACHE_KEY = 'admin:machine:generated_relay_rules:v1';
    public const PUBLIC_STATUS_CACHE_KEY = 'public:machine-status:v1';
    public const TRAFFIC_ALERT_STATE_PREFIX = 'telegram:machine:traffic:v1:';
    public const RENEWAL_ALERT_STATE_PREFIX = 'telegram:machine:renewal:v1:';

    protected $table = 'v2_machine';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'ddns_enabled' => 'boolean',
        'ddns_proxied' => 'boolean',
        'probe_auto_update' => 'boolean',
        'maintenance_until' => 'integer',
        'network_quality_enabled' => 'boolean',
        'network_quality_interval' => 'integer',
        'network_quality_targets' => 'array',
        'machine_group_id' => 'integer',
        'sort' => 'integer',
        'public_visible' => 'boolean',
        'traffic_alert_enabled' => 'boolean',
        'traffic_alert_bytes' => 'integer',
        'traffic_alert_window' => 'integer',
        'traffic_reset_day' => 'integer',
        'traffic_reset_hour' => 'integer',
        'traffic_reset_minute' => 'integer',
        'billing_amount' => 'decimal:2',
        'renew_at' => 'integer',
        'renew_alert_enabled' => 'boolean',
        'renew_alert_days' => 'integer',
        'relay_rules' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function machineGroup()
    {
        return $this->belongsTo(MachineGroup::class, 'machine_group_id');
    }

    public function resolveLocation(array $status = []): array
    {
        $detectedCountryCode = strtoupper(trim((string) ($status['country_code'] ?? '')));
        $detectedCountryName = trim((string) ($status['country'] ?? ''));
        $manualCountryCode = strtoupper(trim((string) ($this->country_code ?? '')));
        $manualCountryName = trim((string) ($this->country_name ?? ''));

        return [
            'country_code' => $manualCountryCode !== '' ? $manualCountryCode : $detectedCountryCode,
            'country_name' => $manualCountryName !== '' ? $manualCountryName : $detectedCountryName,
            'manual_country_code' => $manualCountryCode,
            'manual_country_name' => $manualCountryName,
            'detected_country_code' => $detectedCountryCode,
            'detected_country_name' => $detectedCountryName,
            'location_source' => $manualCountryCode !== '' || $manualCountryName !== '' ? 'manual' : 'auto',
        ];
    }

    public static function normalizeNetworkQualityTargets($targets): array
    {
        if (!is_array($targets) || $targets === []) {
            return self::DEFAULT_NETWORK_QUALITY_TARGETS;
        }

        $normalized = [];
        $seen = [];
        foreach (array_slice($targets, 0, 8) as $index => $target) {
            if (!is_array($target)) {
                continue;
            }
            $name = trim((string) ($target['name'] ?? ''));
            $host = trim((string) ($target['host'] ?? ''));
            if ($name === '' || $host === '' || preg_match('/[\s\/]/', $host)) {
                continue;
            }
            $key = trim((string) ($target['key'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key)) {
                $key = 'target_' . substr(sha1($name . '|' . $host . '|' . $index), 0, 16);
            }
            if (isset($seen[$key])) {
                $key = 'target_' . substr(sha1($key . '|' . $host . '|' . $index), 0, 16);
            }
            $seen[$key] = true;
            $ipVersion = strtolower(trim((string) ($target['ip_version'] ?? 'auto')));
            if (!in_array($ipVersion, ['auto', '4', '6'], true)) {
                $ipVersion = 'auto';
            }
            $probeType = strtolower(trim((string) ($target['probe_type'] ?? 'icmp')));
            if (!in_array($probeType, ['icmp', 'tcp'], true)) {
                $probeType = 'icmp';
            }
            $port = (int) ($target['port'] ?? 80);
            if ($port < 1 || $port > 65535) {
                $port = 80;
            }
            $normalized[] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 64),
                'host' => mb_substr($host, 0, 191),
                'probe_type' => $probeType,
                'port' => $port,
                'ip_version' => $ipVersion,
                'enabled' => (bool) ($target['enabled'] ?? true),
            ];
        }

        return $normalized ?: self::DEFAULT_NETWORK_QUALITY_TARGETS;
    }

    public function resolvedNetworkQualityTargets(): array
    {
        return self::normalizeNetworkQualityTargets($this->network_quality_targets);
    }

    public function isInMaintenance(?int $at = null): bool
    {
        return (int) ($this->getAttributes()['maintenance_until'] ?? 0) > ($at ?? time());
    }

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

    public static function trafficAlertStateCacheKeyForId(int $machineId): string
    {
        return self::TRAFFIC_ALERT_STATE_PREFIX . $machineId;
    }

    public function trafficAlertStateCacheKey(): string
    {
        return self::trafficAlertStateCacheKeyForId((int) $this->getKey());
    }

    public static function renewalAlertStateCacheKeyForId(int $machineId): string
    {
        return self::RENEWAL_ALERT_STATE_PREFIX . $machineId;
    }

    public function renewalAlertStateCacheKey(): string
    {
        return self::renewalAlertStateCacheKeyForId((int) $this->getKey());
    }

    public function trafficPeriodSignature(): string
    {
        return implode(':', [
            $this->resolvedTrafficResetMode(),
            max(300, min(2592000, (int) ($this->traffic_alert_window ?: 86400))),
            max(1, min(28, (int) ($this->traffic_reset_day ?: 1))),
            max(0, min(23, (int) ($this->traffic_reset_hour ?? 0))),
            max(0, min(59, (int) ($this->traffic_reset_minute ?? 0))),
        ]);
    }

    public function resolveTrafficPeriod(
        ?int $at = null,
        ?array $currentState = null,
        ?\DateTimeZone $timezone = null
    ): array {
        $at = $at ?: time();
        $mode = $this->resolvedTrafficResetMode();
        $signature = $this->trafficPeriodSignature();
        $window = max(300, min(2592000, (int) ($this->traffic_alert_window ?: 86400)));

        if ($mode === 'rolling') {
            $currentStart = (int) ($currentState['started_at'] ?? 0);
            $currentEnd = (int) ($currentState['ends_at'] ?? 0);
            if (
                (string) ($currentState['cycle_signature'] ?? '') === $signature
                && $currentStart > 0
                && $at >= $currentStart
                && $at < $currentEnd
            ) {
                return compact('mode', 'signature') + [
                    'started_at' => $currentStart,
                    'ends_at' => $currentEnd,
                ];
            }

            return compact('mode', 'signature') + [
                'started_at' => $at,
                'ends_at' => $at + $window,
            ];
        }

        $timezone = $timezone ?: new \DateTimeZone(date_default_timezone_get());
        $now = (new \DateTimeImmutable('@' . $at))->setTimezone($timezone);
        $hour = max(0, min(23, (int) ($this->traffic_reset_hour ?? 0)));
        $minute = max(0, min(59, (int) ($this->traffic_reset_minute ?? 0)));

        if ($mode === 'monthly') {
            $day = max(1, min(28, (int) ($this->traffic_reset_day ?: 1)));
            $start = $now
                ->setDate((int) $now->format('Y'), (int) $now->format('n'), $day)
                ->setTime($hour, $minute, 0);
            if ($start->getTimestamp() > $at) {
                $previousMonth = $start->modify('first day of previous month');
                $start = $previousMonth
                    ->setDate((int) $previousMonth->format('Y'), (int) $previousMonth->format('n'), $day)
                    ->setTime($hour, $minute, 0);
            }
            $end = $start->modify('+1 month');
        } else {
            $start = $now->setTime($hour, $minute, 0);
            if ($start->getTimestamp() > $at) {
                $start = $start->modify('-1 day');
            }
            $end = $start->modify('+1 day');
        }

        return compact('mode', 'signature') + [
            'started_at' => $start->getTimestamp(),
            'ends_at' => $end->getTimestamp(),
        ];
    }

    private function resolvedTrafficResetMode(): string
    {
        $mode = strtolower(trim((string) ($this->traffic_reset_mode ?: 'rolling')));
        return in_array($mode, ['rolling', 'daily', 'monthly'], true) ? $mode : 'rolling';
    }

    public static function probeCache()
    {
        static $repository = null;
        if ($repository !== null) {
            return $repository;
        }

        try {
            $cache = Cache::store('redis');
            $cache->get('machine:cache:connection-check');
            $repository = $cache;
        } catch (\Throwable $e) {
            $repository = Cache::store('file');
        }

        return $repository;
    }

    public function getRelayRulesAttribute($value): array
    {
        // Historical imports may contain JSON encoded inside a JSON string.
        for ($depth = 0; $depth < 2 && is_string($value); $depth++) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    public static function forgetAdminFetchCache(): void
    {
        self::probeCache()->forget(self::ADMIN_GENERATED_RELAY_RULES_CACHE_KEY);
    }

    public static function forgetPublicStatusCache(): void
    {
        self::probeCache()->forget(self::PUBLIC_STATUS_CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(function (Machine $machine) {
            self::probeCache()->forget($machine->probeAuthCacheKey());
            if ($machine->wasChanged([
                'traffic_alert_window',
                'traffic_reset_mode',
                'traffic_reset_day',
                'traffic_reset_hour',
                'traffic_reset_minute',
            ])) {
                self::probeCache()->forget($machine->trafficAlertStateCacheKey());
            }
            if ($machine->wasChanged(['renew_at', 'renew_alert_enabled', 'renew_alert_days'])) {
                self::probeCache()->forget($machine->renewalAlertStateCacheKey());
            }
            self::forgetAdminFetchCache();
            self::forgetPublicStatusCache();
        });

        static::deleted(function (Machine $machine) {
            self::probeCache()->forget($machine->probeAuthCacheKey());
            self::probeCache()->forget($machine->statusCacheKey());
            self::probeCache()->forget($machine->trafficAlertStateCacheKey());
            self::probeCache()->forget($machine->renewalAlertStateCacheKey());
            self::forgetAdminFetchCache();
            self::forgetPublicStatusCache();
        });
    }
}
