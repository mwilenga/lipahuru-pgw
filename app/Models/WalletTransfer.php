<?php

namespace App\Models;

use App\Enums\WalletTransferSource;
use App\Enums\WalletTransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransfer extends Model
{
    protected $fillable = [
        'transfer_id',
        'merchant_id',
        'from_wallet_id',
        'to_wallet_id',
        'amount',
        'currency',
        'status',
        'source',
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
            'amount' => 'decimal:4',
            'status' => WalletTransferStatus::class,
            'source' => WalletTransferSource::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
