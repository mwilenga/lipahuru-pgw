<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantCommission extends Model
{
    protected $fillable = [
        'merchant_id',
        'operation',
        'commission_type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'operation' => PaymentOperation::class,
            'commission_type' => CommissionType::class,
            'value' => 'decimal:4',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
