<?php

namespace App\Services\Webhook;

use App\Models\IncomingWebhookLog;
use App\Models\Transaction;
use App\Providers\Payment\DTOs\ProviderWebhookEvent;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            $transaction = null;
            $payload = $request->all();
            $data = $payload['data'] ?? $payload;

            DB::transaction(function () use ($event, $log, $data, $providerCode, &$transaction): void {
                $transaction = $this->resolveTransaction($data);

                if ($transaction === null) {
                    $log->update(['status' => 'IGNORED', 'error_message' => 'Transaction not found']);

                    Log::warning('GoDigital inbound webhook ignored', [
                        'provider' => strtoupper($providerCode),
                        'incomingWebhookLogId' => $log->id,
                        'eventType' => $event->eventType,
                        'mappedStatus' => $event->status->value,
                        'transactionId' => $data['transactionId'] ?? null,
                        'providerTransactionId' => $data['providerTransactionId'] ?? null,
                        'reference' => $data['reference'] ?? null,
                        'reason' => 'Transaction not found',
                    ]);

                    return;
                }

                $updates = [
                    'provider_receipt_no' => $event->providerReceiptNo ?? $transaction->provider_receipt_no,
                ];

                $upstreamId = $this->resolveUpstreamTransactionId($data);

                if ($upstreamId !== '') {
                    $updates['provider_transaction_id'] = $upstreamId;
                }

                $transaction->update($updates);

                if ($event->status->value === 'SUCCESS') {
                    $transaction = $this->paymentService->finalizeSuccess($transaction->fresh());
                } else {
                    $transaction = $this->paymentService->finalizeFailure(
                        $transaction->fresh(),
                        $event->failureCode,
                        $event->failureMessage,
                    );
                }

                $log->update(['status' => 'PROCESSED', 'processed_at' => now()]);
            });

            if ($transaction !== null && $log->fresh()->status === 'PROCESSED') {
                $this->merchantWebhookService->dispatchPaymentFinalized($transaction->fresh());

                Log::info('GoDigital inbound webhook processed', [
                    'provider' => strtoupper($providerCode),
                    'incomingWebhookLogId' => $log->id,
                    'lipahuruTransactionId' => $transaction->transaction_id,
                    'reference' => $transaction->reference,
                    'finalStatus' => $transaction->fresh()->status->value,
                    'merchantCallbackUrl' => $transaction->callback_url,
                    'merchantWebhookDispatched' => true,
                ]);
            }
        } catch (\Throwable $exception) {
            $log->update([
                'status' => 'FAILED',
                'error_message' => $exception->getMessage(),
            ]);

            Log::error('GoDigital inbound webhook failed', [
                'provider' => strtoupper($providerCode),
                'incomingWebhookLogId' => $log->id,
                'eventType' => $event->eventType,
                'mappedStatus' => $event->status->value,
                'error' => $exception->getMessage(),
                'payload' => $request->all(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTransaction(array $data): ?Transaction
    {
        foreach ($this->candidateIdentifiers($data) as $candidate) {
            $transaction = $this->findByIdentifier($candidate);

            if ($transaction !== null) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function candidateIdentifiers(array $data): array
    {
        $candidates = [
            (string) ($data['providerTransactionId'] ?? ''),
            (string) ($data['transactionId'] ?? ''),
            (string) ($data['reference'] ?? ''),
            (string) ($data['requestId'] ?? ''),
        ];

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }

    private function findByIdentifier(string $identifier): ?Transaction
    {
        if (str_starts_with($identifier, 'TXN-')) {
            $transaction = $this->transactionRepository->findByTransactionId($identifier);

            if ($transaction !== null) {
                return $transaction;
            }
        }

        $transaction = $this->transactionRepository->findByProviderReference($identifier);

        if ($transaction !== null) {
            return $transaction;
        }

        $transaction = $this->transactionRepository->findByReference($identifier);

        if ($transaction !== null) {
            return $transaction;
        }

        return $this->transactionRepository->findByRequestId($identifier);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveUpstreamTransactionId(array $data): string
    {
        $providerTransactionId = (string) ($data['providerTransactionId'] ?? '');
        $transactionId = (string) ($data['transactionId'] ?? '');

        if ($this->looksLikeUpstreamTransactionId($providerTransactionId)) {
            return $providerTransactionId;
        }

        if ($this->looksLikeUpstreamTransactionId($transactionId)) {
            return $transactionId;
        }

        return $providerTransactionId !== '' ? $providerTransactionId : $transactionId;
    }

    private function looksLikeUpstreamTransactionId(string $value): bool
    {
        return str_starts_with($value, 'GD') || preg_match('/^\d{6,}$/', $value) === 1;
    }
}
