<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantProviderProfile extends Model
{
    protected $fillable = [
        'merchant_id',
        'provider_network_id',
        'is_enabled',
        'min_amount',
        'max_amount',
        'daily_limit',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'min_amount' => 'decimal:4',
            'max_amount' => 'decimal:4',
            'daily_limit' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }
}
