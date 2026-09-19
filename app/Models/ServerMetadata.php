<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMetadata extends Model
{
    protected $table = 'v2_server_metadata';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'server_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
