<?php

namespace App\Jobs;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Providers\Payment\ProviderRouter;
use App\Services\Payment\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileTransactionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?int $transactionId = null,
    ) {
        $this->onQueue('payments');
    }

    public function handle(ProviderRouter $providerRouter, PaymentService $paymentService): void
    {
        $query = Transaction::query()->where('status', TransactionStatus::Reconciling);

        if ($this->transactionId !== null) {
            $query->where('id', $this->transactionId);
        }

        $transactions = $query->limit(100)->get();

        foreach ($transactions as $transaction) {
            $providerCode = $transaction->providerNetwork?->code?->value;

            if ($providerCode === null || $transaction->provider_transaction_id === null) {
                continue;
            }

            $operation = match ($transaction->operation) {
                PaymentOperation::B2cDisbursement => PaymentOperation::B2cDisbursement,
                default => PaymentOperation::C2bPush,
            };

            $provider = $providerRouter->resolve($providerCode, $operation);
            $status = $provider->queryStatus((string) $transaction->provider_transaction_id);

            if ($status->status === TransactionStatus::Success) {
                $paymentService->finalizeSuccess($transaction);
            } elseif ($status->status === TransactionStatus::Failed) {
                $paymentService->finalizeFailure(
                    $transaction,
                    $status->failureCode,
                    $status->failureMessage,
                );
            }
        }
    }
}
