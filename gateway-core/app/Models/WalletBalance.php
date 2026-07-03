<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletBalance extends Model
{
    protected $fillable = [
        'wallet_id',
        'available',
        'reserved',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'decimal:4',
            'reserved' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
