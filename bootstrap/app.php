<?php

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::post('/oauth/token', [\App\Http\Controllers\Api\V1\OAuthTokenController::class, 'issue']);
            Route::post('/internal/webhooks/{provider}', [\App\Http\Controllers\Internal\ProviderWebhookController::class, 'handle']);
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'gateway.headers' => \App\Http\Middleware\ValidateGatewayHeaders::class,
            'gateway.client' => \App\Http\Middleware\ResolveMerchantClient::class,
            'gateway.signature' => \App\Http\Middleware\ValidateHmacSignature::class,
            'gateway.idempotency' => \App\Http\Middleware\ValidateIdempotency::class,
            'portal.merchant' => \App\Http\Middleware\ResolvePortalMerchant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (GatewayException $exception, Request $request) {
            if ($request->is('api/*') || $request->is('oauth/*')) {
                return ApiResponse::failed(
                    $exception->errorCode,
                    $exception->getMessage(),
                    (string) \Illuminate\Support\Str::uuid(),
                    httpStatus: $exception->httpStatus,
                );
            }
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*') || $request->is('oauth/*')) {
                return ApiResponse::failed(
                    GatewayErrorCode::InvalidPayload,
                    $exception->getMessage(),
                    (string) \Illuminate\Support\Str::uuid(),
                    ['errors' => $exception->errors()],
                );
            }
        });
    })->create();
