<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NonceRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'nonce',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
