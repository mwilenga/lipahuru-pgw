<?php

namespace App\Services\Payment;

use App\Enums\DisbursementBatchStatus;
use App\Enums\GatewayErrorCode;
use App\Enums\TransactionStatus;
use App\Exceptions\GatewayException;
use App\Jobs\ProcessBulkDisbursementItemJob;
use App\Models\DisbursementBatch;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkDisbursementService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly TransactionRepositoryInterface $transactionRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Merchant $merchant, array $payload): DisbursementBatch
    {
        $this->paymentService->assertMerchantCanTransact($merchant);

        $items = $this->normalizeItems($merchant, $payload);
        $currency = (string) ($payload['currency'] ?? $merchant->default_currency ?? 'TZS');
        $callbackUrl = $payload['callbackUrl'] ?? $merchant->default_callback_url;

        $totalAmount = '0.0000';
        foreach ($items as $item) {
            $totalAmount = bcadd($totalAmount, $item['amount'], 4);
        }

        $batch = DB::transaction(function () use ($merchant, $payload, $items, $currency, $callbackUrl, $totalAmount): DisbursementBatch {
            $this->assertNoDuplicateRequestIds($merchant, $items);

            $batch = DisbursementBatch::query()->create([
                'batch_id' => $this->generateBatchId(),
                'merchant_id' => $merchant->id,
                'batch_reference' => $payload['batchReference'] ?? null,
                'status' => DisbursementBatchStatus::Pending,
                'currency' => $currency,
                'total_amount' => $totalAmount,
                'total_items' => count($items),
                'pending_count' => count($items),
                'success_count' => 0,
                'failed_count' => 0,
                'callback_url' => $callbackUrl,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            foreach ($items as $item) {
                $providerNetwork = $this->paymentService->resolveProviderNetwork($item['providerCode']);
                $this->paymentService->assertMerchantProfile($merchant, $providerNetwork, $item['amount']);

                $this->paymentService->prepareDisbursement(
                    merchant: $merchant,
                    payload: [
                        'requestId' => $item['requestId'],
                        'reference' => $item['reference'],
                        'externalReference' => $item['externalReference'] ?? null,
                        'amount' => $item['amount'],
                        'currency' => $currency,
                        'msisdn' => $item['msisdn'],
                        'callbackUrl' => $callbackUrl,
                        'narration' => $item['narration'] ?? null,
                        'metadata' => $item['metadata'] ?? null,
                    ],
                    providerNetwork: $providerNetwork,
                    batchId: $batch->id,
                );
            }

            return $batch->fresh(['merchant', 'transactions.providerNetwork']);
        });

        foreach ($batch->transactions as $transaction) {
            $providerCode = $transaction->providerNetwork?->code?->value;

            if ($providerCode === null) {
                continue;
            }

            ProcessBulkDisbursementItemJob::dispatch($transaction->id, $providerCode);
        }

        return $batch->fresh(['merchant', 'transactions.providerNetwork']);
    }

    public function refreshStatus(DisbursementBatch $batch): DisbursementBatch
    {
        $transactions = $batch->transactions()->get();

        $successCount = $transactions->where('status', TransactionStatus::Success)->count();
        $failedCount = $transactions->where('status', TransactionStatus::Failed)->count();
        $totalItems = $transactions->count();
        $pendingCount = $totalItems - $successCount - $failedCount;

        if ($pendingCount > 0) {
            $hasDispatched = $transactions->contains(
                fn (Transaction $transaction) => ! in_array($transaction->status, [
                    TransactionStatus::FundsReserved,
                    TransactionStatus::Success,
                    TransactionStatus::Failed,
                ], true),
            );

            $status = ($hasDispatched || $successCount > 0 || $failedCount > 0)
                ? DisbursementBatchStatus::Processing
                : DisbursementBatchStatus::Pending;
        } elseif ($successCount === $totalItems) {
            $status = DisbursementBatchStatus::Completed;
        } elseif ($failedCount === $totalItems) {
            $status = DisbursementBatchStatus::Failed;
        } else {
            $status = DisbursementBatchStatus::PartiallyCompleted;
        }

        $batch->update([
            'status' => $status,
            'pending_count' => $pendingCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
        ]);

        return $batch->fresh(['merchant', 'transactions.providerNetwork']);
    }

    public function findForMerchantOrFail(string $batchId, Merchant $merchant): DisbursementBatch
    {
        return DisbursementBatch::query()
            ->where('merchant_id', $merchant->id)
            ->where('batch_id', $batchId)
            ->with(['merchant', 'transactions.providerNetwork'])
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(Merchant $merchant, array $payload): array
    {
        $maxItems = (int) config('payment-gateway.bulk_disbursement_max_items', 500);
        $items = $payload['items'] ?? [];
        $defaultProviderCode = isset($payload['providerCode']) ? strtoupper((string) $payload['providerCode']) : null;

        if ($items === [] || count($items) > $maxItems) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                "Bulk disbursement must contain between 1 and {$maxItems} items.",
                httpStatus: 422,
            );
        }

        $seenRequestIds = [];
        $seenPairs = [];
        $normalized = [];

        foreach ($items as $index => $item) {
            $providerCode = strtoupper((string) ($item['providerCode'] ?? $defaultProviderCode ?? ''));

            if ($providerCode === '') {
                throw new GatewayException(
                    GatewayErrorCode::InvalidPayload,
                    "Item {$index}: providerCode is required on each item.",
                    httpStatus: 422,
                );
            }

            $requestId = (string) ($item['requestId'] ?? '');
            $reference = (string) ($item['reference'] ?? '');
            $msisdn = (string) ($item['msisdn'] ?? '');
            $amount = number_format((float) ($item['amount'] ?? 0), 4, '.', '');
            $pairKey = $msisdn.'|'.$reference;

            if (isset($seenRequestIds[$requestId])) {
                throw new GatewayException(
                    GatewayErrorCode::DuplicateRequest,
                    "Duplicate requestId in batch: {$requestId}",
                    httpStatus: 422,
                );
            }

            if (isset($seenPairs[$pairKey])) {
                throw new GatewayException(
                    GatewayErrorCode::InvalidPayload,
                    "Duplicate msisdn/reference pair in batch: {$msisdn} / {$reference}",
                    httpStatus: 422,
                );
            }

            $seenRequestIds[$requestId] = true;
            $seenPairs[$pairKey] = true;

            $normalized[] = [
                'requestId' => $requestId,
                'reference' => $reference,
                'externalReference' => $item['externalReference'] ?? null,
                'providerCode' => $providerCode,
                'amount' => $amount,
                'msisdn' => $msisdn,
                'narration' => $item['narration'] ?? null,
                'metadata' => $item['metadata'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertNoDuplicateRequestIds(Merchant $merchant, array $items): void
    {
        foreach ($items as $item) {
            $existing = $this->transactionRepository->findByMerchantAndRequestId(
                $merchant->id,
                (string) $item['requestId'],
            );

            if ($existing !== null) {
                throw new GatewayException(
                    GatewayErrorCode::DuplicateRequest,
                    "Duplicate requestId: {$item['requestId']}",
                    httpStatus: 409,
                );
            }
        }
    }

    private function generateBatchId(): string
    {
        return 'BATCH-'.strtoupper(Str::random(16));
    }
}
