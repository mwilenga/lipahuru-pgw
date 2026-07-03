<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'refund_id',
        'transaction_id',
        'merchant_id',
        'request_id',
        'amount',
        'currency',
        'status',
        'reason',
        'provider_refund_id',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'request_id' => 'string',
            'amount' => 'decimal:4',
            'finalized_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
