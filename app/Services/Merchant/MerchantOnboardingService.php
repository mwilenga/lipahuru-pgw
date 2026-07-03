<?php

namespace App\Services\Merchant;

use App\Enums\MerchantStatus;
use App\Enums\WalletType;
use App\Models\Merchant;
use App\Models\ProviderNetwork;
use App\Models\WalletBalance;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\OAuthClientRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantOnboardingService
{
    public function __construct(
        private readonly MerchantRepositoryInterface $merchantRepository,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly OAuthClientRepositoryInterface $oauthClientRepository,
        private readonly ApiCredentialService $apiCredentialService,
    ) {}

    /**
     * @param  array<string, mixed>  $merchantData
     * @return array{merchant: Merchant, client_id: string, client_secret: string, signing_secret: string, callback_secret: string}
     */
    public function onboard(array $merchantData): array
    {
        return DB::transaction(function () use ($merchantData): array {
            $merchant = $this->merchantRepository->create([
                'uuid' => (string) Str::uuid(),
                'name' => $merchantData['name'],
                'legal_name' => $merchantData['legal_name'] ?? null,
                'email' => $merchantData['email'],
                'phone' => $merchantData['phone'] ?? null,
                'registration_number' => $merchantData['registration_number'] ?? null,
                'tax_id' => $merchantData['tax_id'] ?? null,
                'status' => MerchantStatus::Pending,
                'environment' => $merchantData['environment'] ?? 'uat',
                'default_currency' => $merchantData['default_currency'] ?? config('payment-gateway.default_currency', 'TZS'),
                'default_callback_url' => $merchantData['default_callback_url'] ?? null,
                'metadata' => $merchantData['metadata'] ?? null,
            ]);

            $this->provisionWalletHierarchy($merchant);

            $credentials = $this->apiCredentialService->issue($merchant);
            $clientId = 'cli_'.Str::lower(Str::random(24));
            $clientSecret = 'cs_'.Str::random(48);

            $this->oauthClientRepository->create([
                'merchant_id' => $merchant->id,
                'client_id' => $clientId,
                'client_secret_hash' => Hash::make($clientSecret),
                'name' => $merchantData['client_name'] ?? 'default',
                'status' => 'ACTIVE',
            ]);

            return [
                'merchant' => $merchant->refresh(),
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'signing_secret' => $credentials['signing_secret'],
                'callback_secret' => $credentials['callback_secret'],
            ];
        });
    }

    private function provisionWalletHierarchy(Merchant $merchant): void
    {
        $currency = $merchant->default_currency;

        $parentWallet = $this->createWalletWithBalance($merchant->id, [
            'wallet_type' => WalletType::MerchantParent,
            'currency' => $currency,
            'name' => "{$merchant->name} Parent Wallet",
            'is_active' => true,
        ]);

        $networks = ProviderNetwork::query()->where('is_active', true)->get();

        foreach ($networks as $network) {
            $providerTotal = $this->createWalletWithBalance($merchant->id, [
                'parent_wallet_id' => $parentWallet->id,
                'provider_network_id' => $network->id,
                'wallet_type' => WalletType::ProviderTotal,
                'currency' => $currency,
                'name' => "{$network->name} Total",
                'is_active' => true,
            ]);

            foreach ([WalletType::CollectionLeaf, WalletType::DisbursementLeaf] as $walletType) {
                $this->createWalletWithBalance($merchant->id, [
                    'parent_wallet_id' => $providerTotal->id,
                    'provider_network_id' => $network->id,
                    'wallet_type' => $walletType,
                    'currency' => $currency,
                    'name' => "{$network->name} ".str_replace('_', ' ', $walletType->value),
                    'is_active' => true,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWalletWithBalance(int $merchantId, array $attributes)
    {
        $wallet = $this->walletRepository->create(array_merge(['merchant_id' => $merchantId], $attributes));

        WalletBalance::query()->create([
            'wallet_id' => $wallet->id,
            'available' => 0,
            'reserved' => 0,
            'total' => 0,
        ]);

        return $wallet;
    }
}
