<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatUserServerHour extends Model
{
    protected $table = 'v2_stat_user_server_hour';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
