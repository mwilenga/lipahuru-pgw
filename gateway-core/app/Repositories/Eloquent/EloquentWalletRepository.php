<?php

namespace App\Repositories\Eloquent;

use App\Enums\WalletType;
use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;

class EloquentWalletRepository implements WalletRepositoryInterface
{
    public function findById(int $id): ?Wallet
    {
        return Wallet::query()->find($id);
    }

    public function findByMerchantAndType(int $merchantId, WalletType $type, ?int $providerNetworkId = null): ?Wallet
    {
        return Wallet::query()
            ->where('merchant_id', $merchantId)
            ->where('wallet_type', $type)
            ->when($providerNetworkId !== null, fn ($query) => $query->where('provider_network_id', $providerNetworkId))
            ->first();
    }

    public function findWithBalanceForUpdate(int $walletId): ?Wallet
    {
        return Wallet::query()
            ->with('balance')
            ->whereKey($walletId)
            ->lockForUpdate()
            ->first();
    }

    public function create(array $attributes): Wallet
    {
        return Wallet::query()->create($attributes);
    }
}
