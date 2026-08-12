<?php

namespace App\Models;

use App\Enums\FloatTopupSource;
use App\Enums\FloatTopupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FloatTopup extends Model
{
    protected $fillable = [
        'topup_id',
        'merchant_id',
        'source',
        'status',
        'currency',
        'total_amount',
        'reference',
        'notes',
        'rejection_reason',
        'requested_by_type',
        'requested_by_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => FloatTopupSource::class,
            'status' => FloatTopupStatus::class,
            'total_amount' => 'decimal:4',
            'reviewed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FloatTopupItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
