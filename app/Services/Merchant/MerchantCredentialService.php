<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\OAuthClient;
use App\Repositories\Contracts\OAuthClientRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantCredentialService
{
    public function __construct(
        private readonly ApiCredentialService $apiCredentialService,
        private readonly OAuthClientRepositoryInterface $oauthClientRepository,
    ) {}

    /**
     * @return array{
     *     clientId: string|null,
     *     clientStatus: string|null,
     *     portalEmail: string|null,
     *     clientSecretHint: string
     * }
     */
    public function summary(Merchant $merchant): array
    {
        $client = $this->activeClient($merchant);
        $portalUser = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        return [
            'clientId' => $client?->client_id,
            'clientStatus' => $client?->status,
            'portalEmail' => $portalUser?->email ?? $merchant->email,
            'clientSecretHint' => 'Client secret is only shown once at creation or after regeneration.',
        ];
    }

    /**
     * @return array{clientId: string, clientSecret: string}
     */
    public function rotateApiCredentials(Merchant $merchant): array
    {
        return DB::transaction(function () use ($merchant): array {
            $client = $this->activeClient($merchant);

            if ($client === null) {
                throw new \RuntimeException('No active API client found for merchant.');
            }

            $clientSecret = 'cs_'.Str::random(48);

            $client->update([
                'client_secret_hash' => Hash::make($clientSecret),
            ]);

            $this->apiCredentialService->rotate($merchant, $clientSecret);

            return [
                'clientId' => $client->client_id,
                'clientSecret' => $clientSecret,
            ];
        });
    }

    /**
     * @return array{portalEmail: string, portalPassword: string}
     */
    public function resetPortalPassword(Merchant $merchant): array
    {
        $portalUser = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if ($portalUser === null) {
            throw new \RuntimeException('No active portal user found for merchant.');
        }

        $password = Str::password(12);

        $portalUser->update([
            'password' => $password,
        ]);

        return [
            'portalEmail' => $portalUser->email,
            'portalPassword' => $password,
        ];
    }

    private function activeClient(Merchant $merchant): ?OAuthClient
    {
        return $this->oauthClientRepository
            ->findActiveByMerchant($merchant->id)
            ->first();
    }
}
