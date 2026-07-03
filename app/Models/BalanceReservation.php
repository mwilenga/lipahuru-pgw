<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceReservation extends Model
{
    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'amount',
        'currency',
        'status',
        'released_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
