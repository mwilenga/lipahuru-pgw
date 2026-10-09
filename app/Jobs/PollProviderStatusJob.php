<?php

namespace App\Jobs;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Providers\Payment\ProviderRouter;
use App\Services\Payment\PaymentService;
use App\Services\Webhook\MerchantWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollProviderStatusJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?int $transactionId = null,
    ) {
        $this->onQueue('payments');
    }

    public function handle(
        ProviderRouter $providerRouter,
        PaymentService $paymentService,
        MerchantWebhookService $merchantWebhookService,
    ): void {
        $query = Transaction::query()
            ->whereIn('status', [TransactionStatus::Acknowledged, TransactionStatus::PendingFinal])
            ->where('updated_at', '<=', now()->subSeconds((int) config('payment-gateway.poll_pending_after_seconds', 120)));

        if ($this->transactionId !== null) {
            $query->where('id', $this->transactionId);
        } else {
            $query->where('created_at', '>=', now()->subHours((int) config('payment-gateway.poll_max_age_hours', 24)));
        }

        $transactions = $query->orderBy('id')->limit(100)->get();

        foreach ($transactions as $transaction) {
            $providerCode = $transaction->providerNetwork?->code?->value;

            if ($providerCode === null || $transaction->provider_transaction_id === null) {
                continue;
            }

            $operation = $transaction->operation === PaymentOperation::B2cDisbursement
                ? PaymentOperation::B2cDisbursement
                : PaymentOperation::C2bPush;

            try {
                $provider = $providerRouter->resolve($providerCode, $operation);
                $status = $provider->queryStatus((string) $transaction->provider_transaction_id);

                if ($status->status === TransactionStatus::Success) {
                    $finalized = $paymentService->finalizeSuccess($transaction);
                } elseif ($status->status === TransactionStatus::Failed) {
                    $finalized = $paymentService->finalizeFailure(
                        $transaction,
                        $status->failureCode,
                        $status->failureMessage,
                    );
                } else {
                    continue;
                }

                $merchantWebhookService->dispatchPaymentFinalized($finalized);
            } catch (\Throwable $exception) {
                Log::warning('Provider status poll skipped transaction', [
                    'transactionId' => $transaction->transaction_id,
                    'providerTransactionId' => $transaction->provider_transaction_id,
                    'providerCode' => $providerCode,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
