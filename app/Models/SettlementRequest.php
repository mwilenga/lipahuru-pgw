<?php

namespace App\Models;

use App\Enums\SettlementRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementRequest extends Model
{
    protected $fillable = [
        'request_id',
        'merchant_id',
        'wallet_id',
        'amount',
        'commission_amount',
        'total_debit',
        'currency',
        'status',
        'memo',
        'rejection_reason',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_branch',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'commission_amount' => 'decimal:4',
            'total_debit' => 'decimal:4',
            'status' => SettlementRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
