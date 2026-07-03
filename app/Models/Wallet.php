<?php

namespace App\Models;

use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Wallet extends Model
{
    protected $fillable = [
        'merchant_id',
        'parent_wallet_id',
        'provider_network_id',
        'wallet_type',
        'currency',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'wallet_type' => WalletType::class,
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function parentWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'parent_wallet_id');
    }

    public function childWallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'parent_wallet_id');
    }

    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(WalletBalance::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function balanceReservations(): HasMany
    {
        return $this->hasMany(BalanceReservation::class);
    }
}
