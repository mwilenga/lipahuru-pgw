<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementItem extends Model
{
    protected $fillable = [
        'settlement_id',
        'transaction_id',
        'amount',
        'fee_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'fee_amount' => 'decimal:4',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
