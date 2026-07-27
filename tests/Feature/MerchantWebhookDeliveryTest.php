<?php

namespace Tests\Feature;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\Webhook\MerchantWebhookService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\GatewayTestCase;

class MerchantWebhookDeliveryTest extends GatewayTestCase
{
    public function test_attempt_delivery_saves_merchant_response_and_increments_sent_count(): void
    {
        Queue::fake();

        Http::fake([
            'https://merchant.test/callback' => Http::sequence()
                ->push(['ok' => false], 500)
                ->push(['received' => true], 200),
        ]);

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-CALLBACK-TEST001',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->providerProfiles()->first()->provider_network_id,
            'request_id' => 'req-callback-001',
            'reference' => 'INV-CALLBACK-001',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Success,
            'amount' => 1000,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'callback_url' => 'https://merchant.test/callback',
            'finalized_at' => now(),
        ]);

        $service = app(MerchantWebhookService::class);
        $delivery = $service->dispatchPaymentFinalized($transaction);

        $this->assertNotNull($delivery);
        $this->assertSame(0, $delivery->sent_count);

        $afterFirst = $service->attemptDelivery($delivery->id);
        $this->assertSame(1, $afterFirst->sent_count);
        $this->assertSame(500, $afterFirst->http_status);
        $this->assertStringContainsString('ok', (string) $afterFirst->response_body);
        $this->assertSame('RETRYING', $afterFirst->status);

        $afterResend = $service->resend($delivery->id);
        $this->assertSame($delivery->id, $afterResend->id);
        $this->assertSame(2, $afterResend->sent_count);
        $this->assertSame(200, $afterResend->http_status);
        $this->assertStringContainsString('received', (string) $afterResend->response_body);
        $this->assertSame('DELIVERED', $afterResend->status);

        $this->assertSame(1, WebhookDelivery::query()->count());
    }
}
