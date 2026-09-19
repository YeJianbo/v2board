<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RavelCredential extends Model
{
    protected $table = 'v2_ravel_credential';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = [
        'capability_key_ciphertext',
    ];
    protected $casts = [
        'server_id' => 'integer',
        'user_id' => 'integer',
        'key_version' => 'integer',
        'policy_id' => 'integer',
        'not_before' => 'integer',
        'not_after' => 'integer',
        'revoked_at' => 'integer',
        'superseded_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function server()
    {
        return $this->belongsTo(ServerV2node::class, 'server_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
