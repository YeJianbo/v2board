<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    public const TYPE_FEATURE = 'feature';
    public const TYPE_PAYMENT = 'payment';

    protected $table = 'v2_plugin';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
        'installed_at' => 'datetime',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
