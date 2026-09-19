<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineUpdateLog extends Model
{
    protected $table = 'v2_machine_update_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'machine_id' => 'integer',
        'requested_at' => 'integer',
        'started_at' => 'integer',
        'completed_at' => 'integer',
        'expires_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
