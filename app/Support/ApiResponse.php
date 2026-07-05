<?php

namespace App\Support;

use App\Enums\GatewayErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Request processed successfully',
        ?string $requestId = null,
        GatewayErrorCode $code = GatewayErrorCode::Success,
    ): JsonResponse {
        return response()->json([
            'status' => 'SUCCESS',
            'code' => $code->value,
            'message' => $message,
            'requestId' => $requestId ?? (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ]);
    }

    public static function failed(
        GatewayErrorCode $code,
        ?string $message = null,
        ?string $requestId = null,
        mixed $data = null,
        int $httpStatus = 400,
    ): JsonResponse {
        return response()->json([
            'status' => 'FAILED',
            'code' => $code->value,
            'message' => $message ?? $code->message(),
            'requestId' => $requestId ?? (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ], $httpStatus);
    }
}
