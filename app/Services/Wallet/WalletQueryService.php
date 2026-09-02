<?php

namespace App\Services\Wallet;

use App\Enums\GatewayErrorCode;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Models\Wallet;
use Illuminate\Support\Collection;

class WalletQueryService
{
    /**
     * @return Collection<int, Wallet>
     */
    public function listForMerchant(Merchant $merchant): Collection
    {
        return Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->where('wallet_type', WalletType::MerchantBalance)
            ->where('is_active', true)
            ->with(['balance', 'providerNetwork'])
            ->get();
    }

    public function getMerchantBalance(Merchant $merchant): Wallet
    {
        $wallet = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->where('wallet_type', WalletType::MerchantBalance)
            ->where('is_active', true)
            ->with('balance')
            ->first();

        if ($wallet === null) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'Merchant balance wallet not found.',
                httpStatus: 404,
            );
        }

        return $wallet;
    }
}
