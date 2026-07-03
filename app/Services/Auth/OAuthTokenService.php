<?php

namespace App\Services\Auth;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Repositories\Contracts\OAuthClientRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class OAuthTokenService
{
    public function __construct(
        private readonly OAuthClientRepositoryInterface $oauthClientRepository,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function issueClientCredentialsToken(string $clientId, string $clientSecret): array
    {
        $oauthClient = $this->oauthClientRepository->findByClientId($clientId);

        if ($oauthClient === null || ! Hash::check($clientSecret, $oauthClient->client_secret_hash)) {
            throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Invalid client credentials.', httpStatus: 401);
        }

        $merchant = $oauthClient->merchant;

        if (! $merchant instanceof Merchant) {
            throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Merchant not found for client.', httpStatus: 401);
        }

        $ttl = (int) config('payment-gateway.token_ttl', 900);

        $tokenResult = $merchant->createToken(
            'gateway-api:'.$clientId,
            ['gateway:payments'],
            now()->addSeconds($ttl),
        );

        $oauthClient->update(['last_used_at' => now()]);

        return [
            'access_token' => $tokenResult->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
        ];
    }
}
