<?php

namespace App\Services\Webhook;

use App\Jobs\DeliverMerchantWebhookJob;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MerchantWebhookService
{
    public function dispatchPaymentFinalized(Transaction $transaction): ?WebhookDelivery
    {
        $merchant = $transaction->merchant;
        $callbackUrl = $transaction->callback_url ?? $merchant->default_callback_url;

        if ($callbackUrl === null || $callbackUrl === '') {
            return null;
        }

        $payload = $this->buildPaymentFinalizedPayload($transaction);

        $delivery = WebhookDelivery::query()->create([
            'callback_id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'transaction_id' => $transaction->id,
            'event_type' => 'PAYMENT_FINALIZED',
            'url' => $callbackUrl,
            'payload' => $payload,
            'attempt' => 0,
            'max_attempts' => (int) config('payment-gateway.callback_max_retries', 10),
            'status' => 'PENDING',
            'next_retry_at' => now(),
        ]);

        $this->queueDelivery($delivery);

        return $delivery;
    }

    public function attemptDelivery(int $deliveryId): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->findOrFail($deliveryId);

        $delivery->increment('attempt');
        $delivery->refresh();

        try {
            $response = Http::timeout((int) config('payment-gateway.callback_timeout', 30))
                ->acceptJson()
                ->asJson()
                ->post($delivery->url, $delivery->payload);

            $merchantResponse = $this->normalizeMerchantResponse($response->body());

            Log::info('Merchant callback response received', [
                'deliveryId' => $delivery->id,
                'url' => $delivery->url,
                'attempt' => $delivery->attempt,
                'httpStatus' => $response->status(),
                'merchantResponse' => $merchantResponse,
            ]);

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => 'DELIVERED',
                    'http_status' => $response->status(),
                    'response_body' => $merchantResponse,
                    'delivered_at' => now(),
                    'next_retry_at' => null,
                ])->save();

                return $delivery->refresh();
            }

            return $this->scheduleRetry($delivery, $response->status(), $merchantResponse);
        } catch (\Throwable $exception) {
            Log::warning('Merchant callback delivery failed', [
                'deliveryId' => $delivery->id,
                'url' => $delivery->url,
                'attempt' => $delivery->attempt,
                'error' => $exception->getMessage(),
            ]);

            return $this->scheduleRetry(
                $delivery,
                null,
                $this->normalizeMerchantResponse($exception->getMessage()),
            );
        }
    }

    private function normalizeMerchantResponse(?string $body): string
    {
        $body ??= '';

        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }

        return mb_substr($body, 0, 65535);
    }

    private function queueDelivery(WebhookDelivery $delivery): void
    {
        DeliverMerchantWebhookJob::dispatch($delivery->id);
    }

    private function scheduleRetry(WebhookDelivery $delivery, ?int $httpStatus, string $responseBody): WebhookDelivery
    {
        if ($delivery->attempt >= $delivery->max_attempts) {
            $delivery->forceFill([
                'status' => 'FAILED',
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
                'next_retry_at' => null,
            ])->save();

            return $delivery->refresh();
        }

        $delays = config('payment-gateway.webhook_retry_delays', [60, 300, 900, 3600, 21600, 86400]);
        $delaySeconds = $delays[min($delivery->attempt - 1, count($delays) - 1)] ?? end($delays);

        $delivery->forceFill([
            'status' => 'RETRYING',
            'http_status' => $httpStatus,
            'response_body' => $responseBody,
            'next_retry_at' => now()->addSeconds((int) $delaySeconds),
        ])->save();

        DeliverMerchantWebhookJob::dispatch($delivery->id)
            ->delay(now()->addSeconds((int) $delaySeconds));

        return $delivery->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentFinalizedPayload(Transaction $transaction): array
    {
        return [
            'event' => 'PAYMENT_FINALIZED',
            'transactionId' => $transaction->transaction_id,
            'requestId' => $transaction->request_id,
            'reference' => $transaction->reference,
            'status' => $transaction->status->value,
            'operation' => $transaction->operation->value,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'msisdn' => $transaction->msisdn,
            'providerTransactionId' => $transaction->provider_transaction_id,
            'providerReceiptNo' => $transaction->provider_receipt_no,
            'failureCode' => $transaction->failure_code,
            'failureMessage' => $transaction->failure_message,
            'finalizedAt' => $transaction->finalized_at?->toIso8601String(),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
