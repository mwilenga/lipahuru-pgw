<?php

namespace App\Repositories\Contracts;

use App\Enums\WalletType;
use App\Models\Wallet;

interface WalletRepositoryInterface
{
    public function findById(int $id): ?Wallet;

    public function findByMerchantAndType(int $merchantId, WalletType $type, ?int $providerNetworkId = null): ?Wallet;

    public function findWithBalanceForUpdate(int $walletId): ?Wallet;

    public function create(array $attributes): Wallet;
}
