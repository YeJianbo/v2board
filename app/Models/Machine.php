<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
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
}
