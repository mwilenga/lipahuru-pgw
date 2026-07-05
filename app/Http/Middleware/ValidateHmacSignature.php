<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Models\ApiCredential;
use App\Services\Auth\HmacSignatureService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateHmacSignature
{
    public function __construct(
        private readonly HmacSignatureService $hmacSignatureService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiCredential|null $credential */
        $credential = $request->attributes->get('api_credential');

        if ($credential === null) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant API credentials are not available for signature validation.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        $previousSecret = null;

        if (
            $credential->previous_signing_secret !== null
            && $credential->rotation_grace_ends_at !== null
            && $credential->rotation_grace_ends_at->isFuture()
        ) {
            $previousSecret = $credential->previous_signing_secret;
        }

        $valid = $this->hmacSignatureService->verify(
            $request,
            $credential->signing_secret,
            $previousSecret,
        );

        if (! $valid) {
            return ApiResponse::failed(
                GatewayErrorCode::SignatureFailed,
                'HMAC signature validation failed.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        return $next($request);
    }
}
