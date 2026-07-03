<?php

namespace App\Services\Wallet;

use App\Enums\GatewayErrorCode;
use App\Enums\ProviderCode;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Models\ProviderNetwork;
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
            ->whereIn('wallet_type', [
                WalletType::MerchantParent,
                WalletType::ProviderTotal,
                WalletType::CollectionLeaf,
                WalletType::DisbursementLeaf,
            ])
            ->with(['balance', 'providerNetwork'])
            ->orderBy('wallet_type')
            ->get();
    }

    public function getByProviderCode(Merchant $merchant, string $providerCode): Wallet
    {
        $networkCode = ProviderCode::tryFrom(strtoupper($providerCode));

        if ($networkCode === null) {
            throw new GatewayException(GatewayErrorCode::UnsupportedProvider);
        }

        $network = ProviderNetwork::query()
            ->where('code', $networkCode)
            ->where('is_active', true)
            ->first();

        if ($network === null) {
            throw new GatewayException(GatewayErrorCode::UnsupportedProvider);
        }

        $wallet = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->where('provider_network_id', $network->id)
            ->where('wallet_type', WalletType::ProviderTotal)
            ->with(['balance', 'providerNetwork', 'childWallets.balance'])
            ->first();

        if ($wallet === null) {
            throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet not found for provider.', httpStatus: 404);
        }

        return $wallet;
    }
}
