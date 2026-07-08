<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invite_user()
    {
        return $this->belongsTo(User::class, 'invite_user_id');
    }

    public function group()
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', trim($email));
    }
}
