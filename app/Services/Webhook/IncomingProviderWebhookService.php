<?php

namespace App\Services\Webhook;

use App\Models\IncomingWebhookLog;
use App\Providers\Payment\DTOs\ProviderWebhookEvent;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncomingProviderWebhookService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly PaymentService $paymentService,
        private readonly MerchantWebhookService $merchantWebhookService,
    ) {}

    public function process(string $providerCode, ProviderWebhookEvent $event, Request $request): void
    {
        $log = IncomingWebhookLog::query()->create([
            'provider_code' => strtoupper($providerCode),
            'event_type' => $event->eventType,
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
            'status' => 'RECEIVED',
        ]);

        try {
            DB::transaction(function () use ($event, $log): void {
                $transaction = $this->transactionRepository->findByProviderReference($event->providerTransactionId);

                if ($transaction === null) {
                    $log->update(['status' => 'IGNORED', 'error_message' => 'Transaction not found']);

                    return;
                }

                $transaction->update([
                    'provider_transaction_id' => $event->providerTransactionId,
                    'provider_receipt_no' => $event->providerReceiptNo ?? $transaction->provider_receipt_no,
                ]);

                if ($event->status->value === 'SUCCESS') {
                    $transaction = $this->paymentService->finalizeSuccess($transaction->fresh());
                } else {
                    $transaction = $this->paymentService->finalizeFailure(
                        $transaction->fresh(),
                        $event->failureCode,
                        $event->failureMessage,
                    );
                }

                $this->merchantWebhookService->dispatchPaymentFinalized($transaction);
                $log->update(['status' => 'PROCESSED', 'processed_at' => now()]);
            });
        } catch (\Throwable $exception) {
            $log->update([
                'status' => 'FAILED',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
