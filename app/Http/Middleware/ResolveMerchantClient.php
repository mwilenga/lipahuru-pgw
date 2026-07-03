<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Repositories\Contracts\OAuthClientRepositoryInterface;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveMerchantClient
{
    public function __construct(
        private readonly OAuthClientRepositoryInterface $oauthClientRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clientId = (string) $request->header('X-Client-Id', '');
        $oauthClient = $this->oauthClientRepository->findByClientId($clientId);

        if ($oauthClient === null || $oauthClient->status !== 'ACTIVE' || $oauthClient->revoked_at !== null) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Invalid or revoked OAuth client.',
                (string) $request->header('X-Request-Id'),
                httpStatus: 401,
            );
        }

        $oauthClient->loadMissing('merchant.apiCredential');
        $merchant = $oauthClient->merchant;

        if (! $merchant instanceof Merchant) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant account not found for OAuth client.',
                (string) $request->header('X-Request-Id'),
                httpStatus: 401,
            );
        }

        $credential = $merchant->apiCredential;

        if (! $credential instanceof ApiCredential) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant API credentials are not provisioned.',
                (string) $request->header('X-Request-Id'),
                httpStatus: 401,
            );
        }

        $request->attributes->set('oauth_client', $oauthClient);
        $request->attributes->set('merchant', $merchant);
        $request->attributes->set('api_credential', $credential);

        return $next($request);
    }
}
