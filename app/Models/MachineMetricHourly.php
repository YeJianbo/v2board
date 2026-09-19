<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineMetricHourly extends Model
{
    protected $table = 'v2_machine_metric_hour';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'machine_id' => 'integer',
        'cpu' => 'float',
        'memory' => 'float',
        'disk' => 'float',
        'swap' => 'float',
        'load_1' => 'float',
        'load_5' => 'float',
        'load_15' => 'float',
        'net_in_rate' => 'integer',
        'net_out_rate' => 'integer',
        'mem_total' => 'integer',
        'mem_used' => 'integer',
        'disk_total' => 'integer',
        'disk_used' => 'integer',
        'swap_total' => 'integer',
        'swap_used' => 'integer',
        'sample_count' => 'integer',
        'recorded_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}
