<?php

namespace Tests\Feature;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\IncomingWebhookLog;
use App\Models\Transaction;
use Illuminate\Support\Facades\Queue;
use Tests\GatewayTestCase;

class ProviderWebhookTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_godigital_webhook_finalizes_acknowledged_collection(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-TESTWEBHOOK0001',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->providerProfiles()->first()->provider_network_id,
            'request_id' => 'req-webhook-001',
            'reference' => 'INV-WEBHOOK-001',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 10000,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'provider_transaction_id' => 'GD-TXN-ABC123',
            'callback_url' => 'https://merchant.test/callback',
        ]);

        $response = $this->postJson('/internal/webhooks/godigital', [
            'eventType' => 'PAYMENT_FINALIZED',
            'data' => [
                'transactionId' => 'GD-TXN-ABC123',
                'providerTransactionId' => '919994765',
                'transactionStatus' => 'SUCCESS',
                'providerReceiptNo' => 'RCPT-TEST-001',
                'reference' => $transaction->reference,
            ],
        ]);

        $response->assertOk()->assertJson(['status' => 'RECEIVED']);

        $transaction->refresh();

        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('RCPT-TEST-001', $transaction->provider_receipt_no);
        $this->assertNotNull($transaction->finalized_at);

        $log = IncomingWebhookLog::query()->latest('id')->first();
        $this->assertSame('PROCESSED', $log->status);
    }

    public function test_godigital_webhook_uses_lipahuru_transaction_id_when_provider_id_differs(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-TESTWEBHOOK0002',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->providerProfiles()->first()->provider_network_id,
            'request_id' => 'req-webhook-002',
            'reference' => 'INV-WEBHOOK-002',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 5000,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'provider_transaction_id' => 'GD-TXN-XYZ789',
        ]);

        $response = $this->postJson('/internal/webhooks/godigital', [
            'eventType' => 'PAYMENT_FINALIZED',
            'data' => [
                'transactionId' => $transaction->transaction_id,
                'providerTransactionId' => '888777666',
                'transactionStatus' => 'SUCCESS',
            ],
        ]);

        $response->assertOk();
        $this->assertSame(TransactionStatus::Success, $transaction->fresh()->status);
    }

    public function test_godigital_webhook_matches_reference_when_transaction_id_field_is_merchant_reference(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-TESTWEBHOOK0003',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->providerProfiles()->first()->provider_network_id,
            'request_id' => 'req-webhook-003',
            'reference' => 'INV-CURL-1783321093',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 100,
            'currency' => 'TZS',
            'msisdn' => '255768102956',
            'provider_transaction_id' => 'GD26070606570673204',
        ]);

        $response = $this->postJson('/internal/webhooks/godigital', [
            'eventType' => 'PAYMENT_FINALIZED',
            'data' => [
                'transactionId' => 'INV-CURL-1783321093',
                'providerTransactionId' => 'GD26070606570673204',
                'transactionStatus' => 'SUCCESS',
                'providerReceiptNo' => 'DG62J1OUJP',
            ],
        ]);

        $response->assertOk()->assertJson(['status' => 'RECEIVED']);

        $transaction->refresh();

        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('GD26070606570673204', $transaction->provider_transaction_id);
        $this->assertSame('DG62J1OUJP', $transaction->provider_receipt_no);

        $log = IncomingWebhookLog::query()->latest('id')->first();
        $this->assertSame('PROCESSED', $log->status);
    }
}
