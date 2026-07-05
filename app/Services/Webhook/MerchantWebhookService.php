<?php

namespace App\Services\Webhook;

use App\Models\Transaction;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Bus;

class MerchantWebhookService
{
    public function dispatchPaymentFinalized(Transaction $transaction): WebhookDelivery
    {
        $merchant = $transaction->merchant;
        $callbackUrl = $transaction->callback_url ?? $merchant->default_callback_url;

        if ($callbackUrl === null || $callbackUrl === '') {
            throw new \InvalidArgumentException('No callback URL configured for transaction or merchant.');
        }

        $payload = $this->buildPaymentFinalizedPayload($transaction);

        $delivery = WebhookDelivery::query()->create([
            'callback_id' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_id' => $merchant->id,
            'transaction_id' => $transaction->id,
            'event_type' => 'PAYMENT_FINALIZED',
            'url' => $callbackUrl,
            'payload' => $payload,
            'attempt' => 1,
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

        try {
            $response = \Illuminate\Support\Facades\Http::timeout((int) config('payment-gateway.callback_timeout', 30))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($delivery->url, $delivery->payload);

            if ($response->successful()) {
                $delivery->update([
                    'status' => 'DELIVERED',
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                    'delivered_at' => now(),
                    'next_retry_at' => null,
                ]);

                return $delivery->refresh();
            }

            return $this->scheduleRetry($delivery, $response->status(), $response->body());
        } catch (\Throwable $exception) {
            return $this->scheduleRetry($delivery, null, $exception->getMessage());
        }
    }

    private function queueDelivery(WebhookDelivery $delivery): void
    {
        Bus::dispatch(function () use ($delivery): void {
            app(self::class)->attemptDelivery($delivery->id);
        })->onQueue('webhooks');
    }

    private function scheduleRetry(WebhookDelivery $delivery, ?int $httpStatus, ?string $responseBody): WebhookDelivery
    {
        $attempt = $delivery->attempt + 1;
        $maxAttempts = $delivery->max_attempts;

        if ($attempt > $maxAttempts) {
            $delivery->update([
                'status' => 'FAILED',
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
                'attempt' => $attempt,
                'next_retry_at' => null,
            ]);

            return $delivery->refresh();
        }

        $delays = config('payment-gateway.webhook_retry_delays', [60, 300, 900, 3600, 21600, 86400]);
        $delaySeconds = $delays[min($attempt - 2, count($delays) - 1)] ?? end($delays);

        $delivery->update([
            'status' => 'RETRYING',
            'http_status' => $httpStatus,
            'response_body' => $responseBody,
            'attempt' => $attempt,
            'next_retry_at' => now()->addSeconds((int) $delaySeconds),
        ]);

        Bus::dispatch(function () use ($delivery): void {
            app(self::class)->attemptDelivery($delivery->id);
        })->onQueue('webhooks')->delay(now()->addSeconds((int) $delaySeconds));

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
