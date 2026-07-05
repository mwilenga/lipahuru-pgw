<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Services\Auth\IdempotencyService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateIdempotency
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = (string) $request->header('X-Idempotency-Key', '');

        if ($idempotencyKey === '') {
            return $next($request);
        }

        /** @var Merchant|null $merchant */
        $merchant = $request->attributes->get('merchant');

        if ($merchant === null) {
            return ApiResponse::failed(
                GatewayErrorCode::AuthenticationFailed,
                'Merchant context is required for idempotency validation.',
                (string) Str::uuid(),
                httpStatus: 401,
            );
        }

        $payload = $request->all();
        $requestHash = $this->idempotencyService->hashRequest($payload);

        try {
            $cached = $this->idempotencyService->check($merchant->id, $idempotencyKey, $requestHash);
        } catch (GatewayException $exception) {
            return ApiResponse::failed(
                $exception->errorCode,
                $exception->getMessage(),
                (string) Str::uuid(),
                httpStatus: $exception->httpStatus,
            );
        }

        if ($cached !== null) {
            return response()->json($cached['response_body'], $cached['http_status']);
        }

        $request->attributes->set('idempotency_key', $idempotencyKey);
        $request->attributes->set('idempotency_request_hash', $requestHash);

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 500) {
            $responseBody = json_decode($response->getContent(), true);

            if (is_array($responseBody)) {
                $this->idempotencyService->store(
                    $merchant->id,
                    $idempotencyKey,
                    $requestHash,
                    $response->getStatusCode(),
                    $responseBody,
                );
            }
        }

        return $response;
    }
}
