<?php

namespace App\Jobs;

use App\Enums\GatewayErrorCode;
use App\Enums\TransactionStatus;
use App\Models\DisbursementBatch;
use App\Models\Transaction;
use App\Services\Payment\BulkDisbursementService;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletLedgerService;
use App\StateMachines\TransactionStateMachine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessBulkDisbursementItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $transactionId,
        public string $providerCode,
    ) {
        $this->onQueue('payments');
    }

    public function handle(
        PaymentService $paymentService,
        BulkDisbursementService $bulkDisbursementService,
    ): void {
        $transaction = Transaction::query()->findOrFail($this->transactionId);

        if ($transaction->status !== TransactionStatus::FundsReserved) {
            return;
        }

        DB::transaction(function () use ($paymentService, $bulkDisbursementService, $transaction): void {
            $paymentService->dispatchDisbursement(
                $transaction->fresh(),
                $this->providerCode,
            );
        });

        if ($transaction->disbursement_batch_id !== null) {
            $batch = DisbursementBatch::query()->find($transaction->disbursement_batch_id);

            if ($batch !== null) {
                $bulkDisbursementService->refreshStatus($batch);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $transaction = Transaction::query()->lockForUpdate()->find($this->transactionId);

            if ($transaction === null || $transaction->status !== TransactionStatus::FundsReserved) {
                return;
            }

            app(WalletLedgerService::class)->releaseFunds($transaction);

            app(TransactionStateMachine::class)->transition(
                $transaction->refresh(),
                TransactionStatus::Failed,
                'JOB_FAILED',
                payload: ['message' => $exception?->getMessage()],
                actor: 'gateway',
                attributes: [
                    'failure_code' => GatewayErrorCode::GeneralError->value,
                    'failure_message' => $exception?->getMessage() ?? 'Bulk disbursement job failed',
                ],
            );

            if ($transaction->disbursement_batch_id !== null) {
                $batch = DisbursementBatch::query()->find($transaction->disbursement_batch_id);

                if ($batch !== null) {
                    app(BulkDisbursementService::class)->refreshStatus($batch);
                }
            }
        });
    }
}
