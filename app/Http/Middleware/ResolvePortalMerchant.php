<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Models\MerchantUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolvePortalMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof MerchantUser) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant portal authentication required.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        $merchant = $user->merchant;

        if ($merchant === null) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant account not found.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        $request->attributes->set('merchant', $merchant);
        $request->attributes->set('merchant_user', $user);

        return $next($request);
    }
}
