<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftcardUsage extends Model
{
    protected $table = 'v2_giftcard_usage';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function giftcard()
    {
        return $this->belongsTo(Giftcard::class, 'giftcard_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
