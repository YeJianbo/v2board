<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineGroup extends Model
{
    protected $table = 'v2_machine_group';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'sort' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function machines()
    {
        return $this->hasMany(Machine::class, 'machine_group_id');
    }
}
