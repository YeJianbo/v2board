<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineNetworkQuality extends Model
{
    protected $table = 'v2_machine_network_quality';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'machine_id' => 'integer',
        'sent' => 'integer',
        'received' => 'integer',
        'packet_loss' => 'float',
        'latency_min' => 'float',
        'latency_avg' => 'float',
        'latency_max' => 'float',
        'target_port' => 'integer',
        'recorded_at' => 'integer',
        'created_at' => 'integer',
    ];
}
