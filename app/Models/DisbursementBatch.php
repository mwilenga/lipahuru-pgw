<?php

namespace App\Models;

use App\Enums\DisbursementBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisbursementBatch extends Model
{
    protected $fillable = [
        'batch_id',
        'merchant_id',
        'batch_reference',
        'status',
        'currency',
        'total_amount',
        'total_items',
        'pending_count',
        'success_count',
        'failed_count',
        'callback_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisbursementBatchStatus::class,
            'total_amount' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
