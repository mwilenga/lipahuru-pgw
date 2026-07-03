<?php

namespace App\Models;

use App\Enums\PaymentOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderRoute extends Model
{
    protected $fillable = [
        'provider_network_id',
        'payment_provider_id',
        'operation',
        'priority',
        'is_active',
        'is_healthy',
    ];

    protected function casts(): array
    {
        return [
            'operation' => PaymentOperation::class,
            'is_active' => 'boolean',
            'is_healthy' => 'boolean',
        ];
    }

    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }
}
