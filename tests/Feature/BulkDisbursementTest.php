<?php

namespace Tests\Feature;

use App\Enums\DisbursementBatchStatus;
use App\Enums\GatewayErrorCode;
use App\Enums\ProviderCode;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Jobs\ProcessBulkDisbursementItemJob;
use App\Models\DisbursementBatch;
use App\Models\OAuthClient;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Providers\Payment\Contracts\PaymentProviderInterface;
use App\Providers\Payment\DTOs\ProviderResponse;
use App\Providers\Payment\ProviderRouter;
use App\Services\Payment\BulkDisbursementService;
use App\Services\Payment\PaymentService;
use App\StateMachines\TransactionStateMachine;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class BulkDisbursementTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_bulk_create_holds_funds_and_dispatches_jobs(): void
    {
        Queue::fake();

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '10000');

        $payload = $this->bulkPayload([
            $this->bulkItem('255754111111', '1000.0000', 'REF-1'),
            $this->bulkItem('255754222222', '2000.0000', 'REF-2'),
            $this->bulkItem('255754333333', '1500.0000', 'REF-3'),
        ]);

        $response = $this->postBulk($credentials, $payload);

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.totalItems', 3)
            ->assertJsonPath('data.totalAmount', '4500.0000');

        $batch = DisbursementBatch::query()->firstOrFail();
        $this->assertSame(3, Transaction::query()->where('disbursement_batch_id', $batch->id)->count());

        $wallet = $this->merchantBalance($merchant);
        $this->assertSame('5500.0000', (string) $wallet->balance->available);
        $this->assertSame('4500.0000', (string) $wallet->balance->reserved);

        Queue::assertPushed(ProcessBulkDisbursementItemJob::class, 3);
    }

    public function test_insufficient_balance_rejects_entire_batch(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '1500');

        $payload = $this->bulkPayload([
            $this->bulkItem('255754111111', '1000.0000', 'REF-A'),
            $this->bulkItem('255754222222', '1000.0000', 'REF-B'),
        ]);

        $response = $this->postBulk($credentials, $payload);

        $response->assertStatus(422);
        $this->assertSame(0, DisbursementBatch::query()->count());
        $this->assertSame(0, Transaction::query()->count());

        $wallet = $this->merchantBalance($merchant);
        $this->assertSame('1500.0000', (string) $wallet->balance->available);
        $this->assertSame('0.0000', (string) $wallet->balance->reserved);
    }

    public function test_duplicate_request_id_in_batch_is_rejected(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '5000');

        $duplicateId = (string) Str::uuid();
        $payload = $this->bulkPayload([
            array_merge($this->bulkItem('255754111111', '1000.0000', 'REF-1'), ['requestId' => $duplicateId]),
            array_merge($this->bulkItem('255754222222', '1000.0000', 'REF-2'), ['requestId' => $duplicateId]),
        ]);

        $response = $this->postBulk($credentials, $payload);

        $response->assertStatus(422);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_existing_request_id_is_rejected(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '5000');

        $existingRequestId = (string) Str::uuid();

        $this->postBulk($credentials, [
            'requestId' => $existingRequestId,
            'providerCode' => ProviderCode::Vodacom->value,
            'amount' => 1000,
            'currency' => 'TZS',
            'msisdn' => '255754999999',
            'reference' => 'SINGLE-'.Str::random(6),
        ], '/v1/payments/disbursements')->assertOk();

        $payload = $this->bulkPayload([
            array_merge($this->bulkItem('255754111111', '1000.0000', 'REF-1'), ['requestId' => $existingRequestId]),
        ]);

        $this->postBulk($credentials, $payload)->assertStatus(409);
    }

    public function test_mixed_providers_debit_the_same_merchant_balance(): void
    {
        Queue::fake();

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '5000');

        $payload = $this->bulkPayload([
            $this->bulkItem('255754111111', '1000.0000', 'VOD-1', ProviderCode::Vodacom->value),
            $this->bulkItem('255754222222', '1500.0000', 'AIR-1', ProviderCode::Airtel->value),
        ]);

        $this->postBulk($credentials, $payload)->assertOk();

        $wallet = $this->merchantBalance($merchant);

        $this->assertSame('2500.0000', (string) $wallet->balance->available);
        $this->assertSame('2500.0000', (string) $wallet->balance->reserved);
        $this->assertSame(2, Transaction::query()->distinct()->count('provider_network_id'));
    }

    public function test_provider_rejection_releases_only_failed_item_funds(): void
    {
        Queue::fake();

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '5000');

        $payload = $this->bulkPayload([
            $this->bulkItem('255754111111', '1000.0000', 'REF-OK'),
            $this->bulkItem('255754222222', '1000.0000', 'REF-FAIL'),
        ]);

        $this->postBulk($credentials, $payload)->assertOk();

        $transactions = Transaction::query()->orderBy('id')->get();
        $paymentService = app(PaymentService::class);

        $paymentService->dispatchDisbursement($transactions[0], ProviderCode::Vodacom->value);

        $this->mock(ProviderRouter::class, function ($mock): void {
            $provider = \Mockery::mock(PaymentProviderInterface::class);
            $provider->shouldReceive('getDriverName')->andReturn('test');
            $provider->shouldReceive('initiateDisbursement')->andReturn(new ProviderResponse(
                success: false,
                status: TransactionStatus::Failed,
                failureCode: GatewayErrorCode::GeneralError->value,
                failureMessage: 'Rejected by provider',
            ));
            $mock->shouldReceive('resolve')->andReturn($provider);
        });

        app(PaymentService::class)->dispatchDisbursement($transactions[1], ProviderCode::Vodacom->value);

        $wallet = $this->merchantBalance($merchant);
        $this->assertSame(TransactionStatus::Failed, $transactions[1]->fresh()->status);
        $this->assertSame(TransactionStatus::Acknowledged, $transactions[0]->fresh()->status);
        $this->assertSame('4000.0000', (string) $wallet->balance->available);
        $this->assertSame('1000.0000', (string) $wallet->balance->reserved);
    }

    public function test_batch_status_endpoint_reports_partial_completion(): void
    {
        Queue::fake();

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $this->fundMerchantBalance($merchant, '5000');

        $payload = $this->bulkPayload([
            $this->bulkItem('255754111111', '1000.0000', 'REF-1'),
            $this->bulkItem('255754222222', '1000.0000', 'REF-2'),
        ]);

        $create = $this->postBulk($credentials, $payload);

        $batchId = (string) $create->json('data.batchId');
        $transactions = Transaction::query()->orderBy('id')->get();

        $stateMachine = app(TransactionStateMachine::class);
        $paymentService = app(PaymentService::class);

        $stateMachine->transition(
            $transactions[0],
            TransactionStatus::Acknowledged,
            'PROVIDER_ACKNOWLEDGED',
            actor: 'test',
        );
        $paymentService->finalizeSuccess($transactions[0]->fresh());

        $paymentService->finalizeFailure(
            $transactions[1],
            GatewayErrorCode::GeneralError->value,
            'Failed item',
        );

        app(BulkDisbursementService::class)->refreshStatus(DisbursementBatch::query()->firstOrFail());

        $token = $this->cachedToken ?? $this->getAccessToken(
            $credentials['client_id'],
            $credentials['client_secret'],
        );
        $hmac = app(\App\Services\Auth\HmacSignatureService::class);
        $path = '/api/v1/payments/disbursements/bulk/'.$batchId;
        $canonical = $hmac->buildCanonicalString('GET', $path, $hmac->hashRequestBody(''));

        $response = $this->call('GET', '/api/v1/payments/disbursements/bulk/'.$batchId, [], [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
            'HTTP_X-Signature' => $hmac->sign($canonical, $credentials['client_secret']),
            'HTTP_Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', DisbursementBatchStatus::PartiallyCompleted->value)
            ->assertJsonPath('data.successCount', 1)
            ->assertJsonPath('data.failedCount', 1);
    }

    /**
     * @param  array{merchant: \App\Models\Merchant, client_id: string, client_secret: string}  $credentials
     */
    private function postBulk(array $credentials, array $payload, string $path = '/v1/payments/disbursements/bulk')
    {
        $client = OAuthClient::query()
            ->where('merchant_id', $credentials['merchant']->id)
            ->firstOrFail();

        return $this->signedPost(
            $path,
            $payload,
            $credentials['merchant'],
            $client,
            $credentials['client_secret'],
            (string) Str::uuid(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function bulkPayload(array $items): array
    {
        return [
            'requestId' => (string) Str::uuid(),
            'batchReference' => 'BATCH-'.Str::random(8),
            'currency' => 'TZS',
            'callbackUrl' => 'https://merchant.test/callback',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bulkItem(
        string $msisdn,
        string $amount,
        string $reference,
        ?string $providerCode = null,
    ): array {
        return [
            'requestId' => (string) Str::uuid(),
            'providerCode' => $providerCode ?? ProviderCode::Vodacom->value,
            'reference' => $reference,
            'msisdn' => $msisdn,
            'amount' => $amount,
        ];
    }

    private function fundMerchantBalance($merchant, string $amount): Wallet
    {
        $wallet = $this->merchantBalance($merchant);
        $normalized = number_format((float) $amount, 4, '.', '');

        $wallet->balance->update([
            'available' => bcadd((string) $wallet->balance->available, $normalized, 4),
            'total' => bcadd((string) $wallet->balance->total, $normalized, 4),
        ]);

        return $wallet->refresh()->load('balance');
    }

    private function merchantBalance($merchant): Wallet
    {
        return $merchant->wallets()
            ->where('wallet_type', WalletType::MerchantBalance)
            ->with('balance')
            ->firstOrFail();
    }
}
