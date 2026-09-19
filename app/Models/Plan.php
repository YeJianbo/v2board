<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public const RESET_TRAFFIC_FOLLOW_SYSTEM = null;
    public const RESET_TRAFFIC_FIRST_DAY_MONTH = 0;
    public const RESET_TRAFFIC_MONTHLY = 1;
    public const RESET_TRAFFIC_NEVER = 2;
    public const RESET_TRAFFIC_FIRST_DAY_YEAR = 3;
    public const RESET_TRAFFIC_YEARLY = 4;

    protected $table = 'v2_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function group()
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }
}
