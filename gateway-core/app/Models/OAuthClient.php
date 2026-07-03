<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthClient extends Model
{
    protected $table = 'merchant_api_clients';

    protected $fillable = [
        'merchant_id',
        'client_id',
        'client_secret_hash',
        'name',
        'status',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = [
        'client_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
