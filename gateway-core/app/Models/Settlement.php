<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    protected $fillable = [
        'settlement_id',
        'merchant_id',
        'settlement_date',
        'status',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'currency',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'gross_amount' => 'decimal:4',
            'fee_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'processed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }
}
