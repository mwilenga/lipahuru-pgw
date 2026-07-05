<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveMerchantClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchant = $request->user();

        if (! $merchant instanceof Merchant) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Authenticated merchant account not found.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        $merchant->loadMissing('apiCredential');
        $credential = $merchant->apiCredential;

        if (! $credential instanceof ApiCredential) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant API credentials are not provisioned.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        $request->attributes->set('merchant', $merchant);
        $request->attributes->set('api_credential', $credential);

        return $next($request);
    }
}
