<?php

namespace App\Services\Merchant;

use App\Models\ApiCredential;
use App\Models\Merchant;
use Illuminate\Support\Str;

class ApiCredentialService
{
    /**
     * @return array{signing_secret: string, callback_secret: string, credential: ApiCredential}
     */
    public function issue(Merchant $merchant): array
    {
        $signingSecret = $this->generateSecret();
        $callbackSecret = $this->generateSecret();

        $credential = ApiCredential::query()->updateOrCreate(
            ['merchant_id' => $merchant->id],
            [
                'signing_secret' => $signingSecret,
                'callback_secret' => $callbackSecret,
                'previous_signing_secret' => null,
                'previous_callback_secret' => null,
                'rotated_at' => null,
                'rotation_grace_ends_at' => null,
            ],
        );

        return [
            'signing_secret' => $signingSecret,
            'callback_secret' => $callbackSecret,
            'credential' => $credential,
        ];
    }

    /**
     * @return array{signing_secret: string, callback_secret: string, credential: ApiCredential}
     */
    public function rotate(Merchant $merchant): array
    {
        $credential = $merchant->apiCredential;

        if ($credential === null) {
            return $this->issue($merchant);
        }

        $signingSecret = $this->generateSecret();
        $callbackSecret = $this->generateSecret();
        $graceHours = (int) config('payment-gateway.credential_rotation_grace_hours', 24);

        $credential->update([
            'previous_signing_secret' => $credential->signing_secret,
            'previous_callback_secret' => $credential->callback_secret,
            'signing_secret' => $signingSecret,
            'callback_secret' => $callbackSecret,
            'rotated_at' => now(),
            'rotation_grace_ends_at' => now()->addHours($graceHours),
        ]);

        return [
            'signing_secret' => $signingSecret,
            'callback_secret' => $callbackSecret,
            'credential' => $credential->refresh(),
        ];
    }

    public function revoke(Merchant $merchant): void
    {
        $merchant->apiCredential?->update([
            'signing_secret' => $this->generateSecret(),
            'callback_secret' => $this->generateSecret(),
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

    public function getCallbackSecret(Merchant $merchant): ?string
    {
        return $merchant->apiCredential?->callback_secret;
    }

    private function generateSecret(): string
    {
        return 'sk_'.Str::random(48);
    }
}
