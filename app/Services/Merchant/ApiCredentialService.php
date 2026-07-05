<?php

namespace App\Services\Merchant;

use App\Models\ApiCredential;
use App\Models\Merchant;

class ApiCredentialService
{
    /**
     * @return array{signing_secret: string, credential: ApiCredential}
     */
    public function issue(Merchant $merchant, string $clientSecret): array
    {
        $credential = ApiCredential::query()->updateOrCreate(
            ['merchant_id' => $merchant->id],
            [
                'signing_secret' => $clientSecret,
                'callback_secret' => '',
                'previous_signing_secret' => null,
                'previous_callback_secret' => null,
                'rotated_at' => null,
                'rotation_grace_ends_at' => null,
            ],
        );

        return [
            'signing_secret' => $clientSecret,
            'credential' => $credential,
        ];
    }

    /**
     * @return array{signing_secret: string, credential: ApiCredential}
     */
    public function rotate(Merchant $merchant, string $newClientSecret): array
    {
        $credential = $merchant->apiCredential;

        if ($credential === null) {
            return $this->issue($merchant, $newClientSecret);
        }

        $graceHours = (int) config('payment-gateway.credential_rotation_grace_hours', 24);

        $credential->update([
            'previous_signing_secret' => $credential->signing_secret,
            'previous_callback_secret' => null,
            'signing_secret' => $newClientSecret,
            'callback_secret' => '',
            'rotated_at' => now(),
            'rotation_grace_ends_at' => now()->addHours($graceHours),
        ]);

        return [
            'signing_secret' => $newClientSecret,
            'credential' => $credential->refresh(),
        ];
    }

    public function revoke(Merchant $merchant): void
    {
        $merchant->apiCredential?->update([
            'signing_secret' => 'revoked_'.bin2hex(random_bytes(16)),
            'callback_secret' => '',
            'previous_signing_secret' => null,
            'previous_callback_secret' => null,
            'rotated_at' => now(),
            'rotation_grace_ends_at' => now(),
        ]);

        $merchant->oauthClients()
            ->whereNull('revoked_at')
            ->update([
                'status' => 'REVOKED',
                'revoked_at' => now(),
            ]);
    }

    /**
     * @return list<string>
     */
    public function getActiveSigningSecrets(Merchant $merchant): array
    {
        $credential = $merchant->apiCredential;

        if ($credential === null) {
            return [];
        }

        $secrets = [$credential->signing_secret];

        if (
            $credential->previous_signing_secret !== null
            && $credential->rotation_grace_ends_at !== null
            && $credential->rotation_grace_ends_at->isFuture()
        ) {
            $secrets[] = $credential->previous_signing_secret;
        }

        return $secrets;
    }
}
