<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Giftcard extends Model
{
    protected $table = 'v2_giftcard';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'used_user_ids' => 'array',
        'enabled' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(self::class, 'template_id');
    }

    public function codes()
    {
        return $this->hasMany(self::class, 'template_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function usages()
    {
        return $this->hasMany(GiftcardUsage::class, 'giftcard_id');
    }
}
