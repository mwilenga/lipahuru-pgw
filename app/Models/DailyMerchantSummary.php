<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMerchantSummary extends Model
{
    protected $fillable = [
        'merchant_id',
        'summary_date',
        'total_transactions',
        'successful_transactions',
        'failed_transactions',
        'total_volume',
        'successful_volume',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'summary_date' => 'date',
            'total_volume' => 'decimal:4',
            'successful_volume' => 'decimal:4',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
