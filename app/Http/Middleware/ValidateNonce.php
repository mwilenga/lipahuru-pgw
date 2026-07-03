<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Services\Auth\NonceService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateNonce
{
    public function __construct(
        private readonly NonceService $nonceService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clientId = (string) $request->header('X-Client-Id', '');
        $nonce = (string) $request->header('X-Nonce', '');

        try {
            $this->nonceService->consume($clientId, $nonce);
        } catch (GatewayException $exception) {
            return ApiResponse::failed(
                $exception->errorCode,
                $exception->getMessage(),
                (string) $request->header('X-Request-Id'),
                httpStatus: $exception->httpStatus,
            );
        } catch (\Throwable) {
            return ApiResponse::failed(
                GatewayErrorCode::ReplayProtectionFailed,
                'Nonce validation failed.',
                (string) $request->header('X-Request-Id'),
                httpStatus: 401,
            );
        }

        return $next($request);
    }
}
