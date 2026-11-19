<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderFeeRate extends Model
{
    protected $fillable = [
        'provider_network_id',
        'operation',
        'commission_type',
        'value',
        'is_active',
        'effective_from',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'operation' => PaymentOperation::class,
            'commission_type' => CommissionType::class,
            'value' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }
}
