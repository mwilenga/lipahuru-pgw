<?php

namespace Tests\Feature;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Jobs\DeliverMerchantWebhookJob;
use App\Jobs\PollProviderStatusJob;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Providers\Payment\Contracts\PaymentProviderInterface;
use App\Providers\Payment\DTOs\ProviderStatusResponse;
use App\Providers\Payment\ProviderRouter;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\GatewayTestCase;

class PollProviderStatusCallbackTest extends GatewayTestCase
{
    public function test_polled_success_queues_merchant_callback(): void
    {
        Queue::fake();

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-TESTPOLL0001',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->providerProfiles()->first()->provider_network_id,
            'request_id' => 'req-poll-001',
            'reference' => 'INV-POLL-001',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 1000,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'provider_transaction_id' => 'GD-POLL-001',
            'callback_url' => 'https://merchant.test/callback',
        ]);
        Transaction::query()->whereKey($transaction->id)->update(['updated_at' => now()->subHour()]);

        $provider = Mockery::mock(PaymentProviderInterface::class);
        $provider->shouldReceive('queryStatus')
            ->once()
            ->with('GD-POLL-001')
            ->andReturn(new ProviderStatusResponse('GD-POLL-001', TransactionStatus::Success));

        $router = Mockery::mock(ProviderRouter::class);
        $router->shouldReceive('resolve')->andReturn($provider);
        $this->app->instance(ProviderRouter::class, $router);

        $this->app->call([new PollProviderStatusJob($transaction->id), 'handle']);

        $this->assertSame(TransactionStatus::Success, $transaction->fresh()->status);

        $delivery = WebhookDelivery::query()->where('transaction_id', $transaction->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame('https://merchant.test/callback', $delivery->url);
        $this->assertSame('SUCCESS', $delivery->payload['status']);

        Queue::assertPushedOn('webhooks', DeliverMerchantWebhookJob::class);
    }

    public function test_provider_error_on_one_transaction_does_not_stop_others(): void
    {
        Queue::fake();

        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $networkId = $merchant->providerProfiles()->first()->provider_network_id;

        $missing = $this->pendingTransaction($merchant->id, $networkId, 'TXN-TESTPOLL0002', 'missing-at-provider');
        $found = $this->pendingTransaction($merchant->id, $networkId, 'TXN-TESTPOLL0003', 'GD-POLL-003');

        $provider = Mockery::mock(PaymentProviderInterface::class);
        $provider->shouldReceive('queryStatus')
            ->with('missing-at-provider')
            ->andThrow(new \RuntimeException('Transaction not found'));
        $provider->shouldReceive('queryStatus')
            ->with('GD-POLL-003')
            ->andReturn(new ProviderStatusResponse('GD-POLL-003', TransactionStatus::Success));

        $router = Mockery::mock(ProviderRouter::class);
        $router->shouldReceive('resolve')->andReturn($provider);
        $this->app->instance(ProviderRouter::class, $router);

        $this->app->call([new PollProviderStatusJob, 'handle']);

        $this->assertSame(TransactionStatus::Acknowledged, $missing->fresh()->status);
        $this->assertSame(TransactionStatus::Success, $found->fresh()->status);
    }

    private function pendingTransaction(int $merchantId, int $networkId, string $transactionId, string $providerRef): Transaction
    {
        $transaction = Transaction::query()->create([
            'transaction_id' => $transactionId,
            'merchant_id' => $merchantId,
            'provider_network_id' => $networkId,
            'request_id' => 'req-'.$transactionId,
            'reference' => 'INV-'.$transactionId,
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 1000,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'provider_transaction_id' => $providerRef,
        ]);
        Transaction::query()->whereKey($transaction->id)->update(['updated_at' => now()->subHour()]);

        return $transaction;
    }
}
