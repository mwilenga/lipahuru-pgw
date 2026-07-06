<?php

use App\Http\Controllers\Admin\V1\AdminAuthController;
use App\Http\Controllers\Admin\V1\AdminMerchantController;
use App\Http\Controllers\Admin\V1\AdminMonitoringController;
use App\Http\Controllers\Admin\V1\AdminPortalController;
use App\Http\Controllers\Admin\V1\AdminProviderController;
use App\Http\Controllers\Admin\V1\AdminReportController;
use App\Http\Controllers\Admin\V1\AdminSettlementController;
use App\Http\Controllers\Admin\V1\AdminTransactionController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\DisbursementController;
use App\Http\Controllers\Api\V1\MerchantAuthController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Portal\MerchantPortalController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/v1/login', [AdminAuthController::class, 'login']);

Route::prefix('v1')->group(function (): void {
    Route::post('/merchant/register', [MerchantAuthController::class, 'register']);
    Route::post('/merchant/login', [MerchantAuthController::class, 'login']);

    Route::middleware([
        'auth:api',
        'gateway.client',
        'gateway.headers',
        'gateway.signature',
    ])->group(function (): void {
        Route::get('/wallets', [WalletController::class, 'index']);
        Route::get('/wallets/{providerCode}', [WalletController::class, 'show']);

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/payments/{transactionId}', [TransactionController::class, 'show']);

        Route::get('/webhooks', [WebhookController::class, 'index']);
        Route::post('/webhooks', [WebhookController::class, 'store']);
        Route::post('/webhooks/{id}/retry', [WebhookController::class, 'retry']);

        Route::middleware('gateway.idempotency')->group(function (): void {
            Route::post('/payments/collections/push', [CollectionController::class, 'push'])
                ->middleware('gateway.headers:true');
            Route::post('/payments/disbursements', [DisbursementController::class, 'store'])
                ->middleware('gateway.headers:true');
            Route::post('/payments/{transactionId}/refunds', [RefundController::class, 'store'])
                ->middleware('gateway.headers:true');
        });
    });
});

Route::prefix('v1/portal')
    ->middleware(['auth:merchant', 'portal.merchant'])
    ->group(function (): void {
        Route::get('/me', [MerchantPortalController::class, 'me']);
        Route::get('/dashboard', [MerchantPortalController::class, 'dashboard']);
        Route::get('/wallets', [MerchantPortalController::class, 'wallets']);
        Route::get('/transactions', [MerchantPortalController::class, 'transactions']);
    });

Route::prefix('admin/v1')
    ->middleware(['auth:admin'])
    ->group(function (): void {
        Route::get('/health', [AdminMonitoringController::class, 'health']);
        Route::get('/dashboard', [AdminPortalController::class, 'dashboard']);

        Route::apiResource('merchants', AdminMerchantController::class);
        Route::post('/merchants/{merchant}/approve', [AdminMerchantController::class, 'approve']);
        Route::post('/merchants/{merchant}/suspend', [AdminMerchantController::class, 'suspend']);
        Route::get('/merchants/{merchant}/credentials', [AdminMerchantController::class, 'credentials']);
        Route::post('/merchants/{merchant}/rotate-credentials', [AdminMerchantController::class, 'rotateCredentials']);
        Route::post('/merchants/{merchant}/reset-portal-password', [AdminMerchantController::class, 'resetPortalPassword']);

        Route::get('/providers', [AdminProviderController::class, 'providers']);
        Route::get('/networks', [AdminProviderController::class, 'networks']);
        Route::get('/routes', [AdminProviderController::class, 'routes']);

        Route::get('/webhook-logs', [AdminMonitoringController::class, 'webhookLogs']);
        Route::get('/audit-logs', [AdminMonitoringController::class, 'auditLogs']);

        Route::get('/reports/merchant-summary', [AdminReportController::class, 'merchantSummary']);

        Route::get('/settlements', [AdminSettlementController::class, 'index']);
        Route::post('/settlements/trigger', [AdminSettlementController::class, 'trigger']);

        Route::get('/transactions', [AdminTransactionController::class, 'index']);
    });
