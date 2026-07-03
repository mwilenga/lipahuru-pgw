<?php

namespace App\Http\Middleware;

use App\Enums\GatewayErrorCode;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTimestamp
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Timestamp', '');

        try {
            $requestTime = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return ApiResponse::failed(
                GatewayErrorCode::ReplayProtectionFailed,
                'Invalid X-Timestamp header.',
                (string) $request->header('X-Request-Id'),
                httpStatus: 401,
            );
        }

        $tolerance = (int) config('payment-gateway.timestamp_tolerance', 300);
        $skew = abs(now()->diffInSeconds($requestTime, false));

        if ($skew > $tolerance) {
            return ApiResponse::failed(
                GatewayErrorCode::ReplayProtectionFailed,
                'Request timestamp is outside the allowed tolerance window.',
                (string) $request->header('X-Request-Id'),
                httpStatus: 401,
            );
        }

        return $next($request);
    }
}
