<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    protected $fillable = [
        'merchant_id',
        'signing_secret',
        'callback_secret',
        'previous_signing_secret',
        'previous_callback_secret',
        'rotated_at',
        'rotation_grace_ends_at',
    ];

    protected $hidden = [
        'signing_secret',
        'callback_secret',
        'previous_signing_secret',
        'previous_callback_secret',
    ];

    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'callback_secret' => 'encrypted',
            'previous_signing_secret' => 'encrypted',
            'previous_callback_secret' => 'encrypted',
            'rotated_at' => 'datetime',
            'rotation_grace_ends_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
