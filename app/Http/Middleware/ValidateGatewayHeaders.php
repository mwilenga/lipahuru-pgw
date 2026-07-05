<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateGatewayHeaders
{
    /**
     * @var list<string>
     */
    private const REQUIRED_HEADERS = [
        'Authorization',
        'X-Signature',
    ];

    public function handle(Request $request, Closure $next, string $requireIdempotency = 'false'): Response
    {
        $missing = [];

        foreach (self::REQUIRED_HEADERS as $header) {
            if (! $request->hasHeader($header) || trim((string) $request->header($header)) === '') {
                $missing[] = $header;
            }
        }

        if (filter_var($requireIdempotency, FILTER_VALIDATE_BOOLEAN)) {
            if (! $request->hasHeader('X-Idempotency-Key') || trim((string) $request->header('X-Idempotency-Key')) === '') {
                $missing[] = 'X-Idempotency-Key';
            }
        }

        if ($missing !== []) {
            return ApiResponse::failed(
                GatewayErrorCode::InvalidPayload,
                'Missing required gateway headers: '.implode(', ', $missing),
                (string) Str::uuid(),
                ['missing' => $missing],
                400,
            );
        }

        return $next($request);
    }
}
