<?php

namespace Tests\Feature;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\MerchantUser;
use App\Models\ProviderFeeRate;
use App\Models\ProviderNetwork;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Auth\HmacSignatureService;
use App\Services\Payment\PaymentService;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class ProviderFeeTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_adding_provider_rate_deactivates_previous_active_for_same_network_and_operation(): void
    {
        $network = ProviderNetwork::query()->where('code', ProviderCode::Vodacom)->firstOrFail();

        $first = $this->withToken($this->adminToken(), 'Bearer')
            ->postJson('/api/admin/v1/settings/provider-fee-rates', [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::C2bPush->value,
                'commissionType' => CommissionType::Percent->value,
                'value' => '0.75',
                'effectiveFrom' => '2025-01-01',
            ])
            ->assertOk()
            ->json('data.id');

        $second = $this->withToken($this->adminToken(), 'Bearer')
            ->postJson('/api/admin/v1/settings/provider-fee-rates', [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::C2bPush->value,
                'commissionType' => CommissionType::Percent->value,
                'value' => '1.00',
                'effectiveFrom' => '2026-10-01',
            ])
            ->assertOk()
            ->json('data.id');

        $this->assertFalse((bool) ProviderFeeRate::query()->findOrFail($first)->is_active);
        $this->assertTrue((bool) ProviderFeeRate::query()->findOrFail($second)->is_active);
        $this->assertSame(
            1,
            ProviderFeeRate::query()
                ->where('provider_network_id', $network->id)
                ->where('operation', PaymentOperation::C2bPush)
                ->where('is_active', true)
                ->count(),
        );
    }

    public function test_admin_can_update_and_delete_provider_fee_rate(): void
    {
        $network = ProviderNetwork::query()->where('code', ProviderCode::Vodacom)->firstOrFail();

        $id = $this->withToken($this->adminToken(), 'Bearer')
            ->postJson('/api/admin/v1/settings/provider-fee-rates', [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::B2cDisbursement->value,
                'commissionType' => CommissionType::Fixed->value,
                'value' => '500',
                'effectiveFrom' => '2026-10-01',
                'isActive' => true,
            ])
            ->assertOk()
            ->json('data.id');

        $this->withToken($this->adminToken(), 'Bearer')
            ->putJson("/api/admin/v1/settings/provider-fee-rates/{$id}", [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::B2cDisbursement->value,
                'commissionType' => CommissionType::Fixed->value,
                'value' => '450',
                'effectiveFrom' => '2026-10-01',
                'notes' => 'Corrected Oct rate',
                'isActive' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.value', '450.0000')
            ->assertJsonPath('data.notes', 'Corrected Oct rate')
            ->assertJsonPath('data.isActive', false);

        $this->assertSame('450.0000', (string) ProviderFeeRate::query()->findOrFail($id)->value);

        $this->withToken($this->adminToken(), 'Bearer')
            ->deleteJson("/api/admin/v1/settings/provider-fee-rates/{$id}")
            ->assertOk();

        $this->assertNull(ProviderFeeRate::query()->find($id));
    }

    public function test_activating_rate_deactivates_other_active_for_same_network_and_operation(): void
    {
        $network = ProviderNetwork::query()->where('code', ProviderCode::Vodacom)->firstOrFail();

        $activeId = $this->withToken($this->adminToken(), 'Bearer')
            ->postJson('/api/admin/v1/settings/provider-fee-rates', [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::C2bPush->value,
                'commissionType' => CommissionType::Percent->value,
                'value' => '0.75',
                'isActive' => true,
            ])
            ->assertOk()
            ->json('data.id');

        $inactiveId = $this->withToken($this->adminToken(), 'Bearer')
            ->postJson('/api/admin/v1/settings/provider-fee-rates', [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::C2bPush->value,
                'commissionType' => CommissionType::Percent->value,
                'value' => '1.10',
                'isActive' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.isActive', false)
            ->json('data.id');

        $this->assertTrue((bool) ProviderFeeRate::query()->findOrFail($activeId)->is_active);

        $this->withToken($this->adminToken(), 'Bearer')
            ->putJson("/api/admin/v1/settings/provider-fee-rates/{$inactiveId}", [
                'providerNetworkId' => $network->id,
                'operation' => PaymentOperation::C2bPush->value,
                'commissionType' => CommissionType::Percent->value,
                'value' => '1.10',
                'isActive' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.isActive', true);

        $this->assertFalse((bool) ProviderFeeRate::query()->findOrFail($activeId)->is_active);
        $this->assertTrue((bool) ProviderFeeRate::query()->findOrFail($inactiveId)->is_active);
        $this->assertSame(
            1,
            ProviderFeeRate::query()
                ->where('provider_network_id', $network->id)
                ->where('operation', PaymentOperation::C2bPush)
                ->where('is_active', true)
                ->count(),
        );
    }

    public function test_finalize_success_snapshots_merchant_and_provider_fees_for_c2b_and_b2c(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        /** @var Merchant $merchant */
        $merchant = $credentials['merchant'];
        $network = ProviderNetwork::query()->where('code', ProviderCode::Vodacom)->firstOrFail();

        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::C2bPush],
            ['commission_type' => CommissionType::Percent, 'value' => '2'],
        );
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::B2cDisbursement],
            ['commission_type' => CommissionType::Fixed, 'value' => '600'],
        );

        ProviderFeeRate::query()->create([
            'provider_network_id' => $network->id,
            'operation' => PaymentOperation::C2bPush,
            'commission_type' => CommissionType::Percent,
            'value' => '1.00',
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ]);
        // B2C settings are ignored for provider fee (always FIXED 500).
        ProviderFeeRate::query()->create([
            'provider_network_id' => $network->id,
            'operation' => PaymentOperation::B2cDisbursement,
            'commission_type' => CommissionType::Fixed,
            'value' => '400',
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ]);

        $c2bId = $this->postCollection($credentials, [
            'amount' => 10000.00,
            'providerCode' => ProviderCode::Vodacom->value,
        ])->json('data.transactionId');

        $c2b = Transaction::query()->where('transaction_id', $c2bId)->firstOrFail();
        app(PaymentService::class)->finalizeSuccess($c2b);
        $c2b->refresh();

        $this->assertSame('200.0000', (string) $c2b->merchant_fee_amount);
        $this->assertSame('100.0000', (string) $c2b->provider_fee_amount);
        $this->assertNotNull($c2b->provider_fee_rate_id);

        $this->creditDisbursementWallet($merchant, ProviderCode::Vodacom->value, '1600');
        $b2cId = $this->postDisbursement($credentials, [
            'amount' => 1000.00,
            'providerCode' => ProviderCode::Vodacom->value,
        ])->json('data.transactionId');

        $b2c = Transaction::query()->where('transaction_id', $b2cId)->firstOrFail();
        app(PaymentService::class)->finalizeSuccess($b2c);
        $b2c->refresh();

        $this->assertSame('600.0000', (string) $b2c->merchant_fee_amount);
        $this->assertSame('500.0000', (string) $b2c->provider_fee_amount);
        $this->assertNull($b2c->provider_fee_rate_id);
    }

    public function test_backfill_writes_legacy_provider_fees_before_october_and_settings_after(): void
    {
        [$merchant] = $this->createMerchantPortalSession();
        $network = ProviderNetwork::query()->where('code', ProviderCode::Vodacom)->firstOrFail();

        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::C2bPush],
            ['commission_type' => CommissionType::Percent, 'value' => '2'],
        );
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::B2cDisbursement],
            ['commission_type' => CommissionType::Fixed, 'value' => '600'],
        );

        $c2bRate = ProviderFeeRate::query()->create([
            'provider_network_id' => $network->id,
            'operation' => PaymentOperation::C2bPush,
            'commission_type' => CommissionType::Percent,
            'value' => '1.5',
            'is_active' => true,
            'effective_from' => '2026-10-01',
        ]);
        // B2C settings must be ignored — provider B2C stays FIXED 500.
        ProviderFeeRate::query()->create([
            'provider_network_id' => $network->id,
            'operation' => PaymentOperation::B2cDisbursement,
            'commission_type' => CommissionType::Percent,
            'value' => '1',
            'is_active' => true,
            'effective_from' => '2026-10-01',
        ]);

        $c2bLegacy = $this->createLegacyTransaction(
            $merchant,
            $network->id,
            PaymentOperation::C2bPush,
            '10000',
            '2026-09-15 10:00:00',
        );
        $b2cLegacy = $this->createLegacyTransaction(
            $merchant,
            $network->id,
            PaymentOperation::B2cDisbursement,
            '2000',
            '2026-09-15 10:00:00',
        );
        $smallB2cLegacy = $this->createLegacyTransaction(
            $merchant,
            $network->id,
            PaymentOperation::B2cDisbursement,
            '200',
            '2026-09-20 10:00:00',
        );
        $c2bOct = $this->createLegacyTransaction(
            $merchant,
            $network->id,
            PaymentOperation::C2bPush,
            '10000',
            '2026-10-05 10:00:00',
        );
        $b2cOct = $this->createLegacyTransaction(
            $merchant,
            $network->id,
            PaymentOperation::B2cDisbursement,
            '10000',
            '2026-10-05 10:00:00',
        );

        Artisan::call('transactions:backfill-fees');

        $c2bLegacy->refresh();
        $b2cLegacy->refresh();
        $smallB2cLegacy->refresh();
        $c2bOct->refresh();
        $b2cOct->refresh();

        $this->assertSame('200.0000', (string) $c2bLegacy->merchant_fee_amount);
        $this->assertSame('75.0000', (string) $c2bLegacy->provider_fee_amount);
        $this->assertNull($c2bLegacy->provider_fee_rate_id);

        $this->assertSame('600.0000', (string) $b2cLegacy->merchant_fee_amount);
        $this->assertSame('500.0000', (string) $b2cLegacy->provider_fee_amount);

        $this->assertSame('200.0000', (string) $smallB2cLegacy->merchant_fee_amount);
        $this->assertSame('200.0000', (string) $smallB2cLegacy->provider_fee_amount);

        $this->assertSame('150.0000', (string) $c2bOct->provider_fee_amount);
        $this->assertSame($c2bRate->id, $c2bOct->provider_fee_rate_id);
        $this->assertSame('500.0000', (string) $b2cOct->provider_fee_amount);
        $this->assertNull($b2cOct->provider_fee_rate_id);
    }

    public function test_admin_transaction_payload_includes_provider_fee_and_summary_net_fee(): void
    {
        [$merchant, $merchantToken] = $this->createMerchantPortalSession();
        $network = ProviderNetwork::query()->where('code', ProviderCode::Vodacom)->firstOrFail();

        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::C2bPush],
            ['commission_type' => CommissionType::Percent, 'value' => '2'],
        );

        Transaction::query()->create([
            'transaction_id' => 'TXN-'.strtoupper(Str::random(12)),
            'merchant_id' => $merchant->id,
            'provider_network_id' => $network->id,
            'request_id' => (string) Str::uuid(),
            'reference' => 'REF-'.Str::random(8),
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Success,
            'amount' => '10000',
            'merchant_fee_amount' => '200',
            'provider_fee_amount' => '75',
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'finalized_at' => now(),
        ]);

        $admin = $this->withToken($this->adminToken(), 'Bearer')
            ->getJson('/api/admin/v1/transactions?operation=C2B_USSD_PUSH')
            ->assertOk();

        $admin->assertJsonPath('data.transactions.0.fee', '200.0000')
            ->assertJsonPath('data.transactions.0.providerFee', '75.0000')
            ->assertJsonPath('data.summary.feeAmount', '200.0000')
            ->assertJsonPath('data.summary.providerFeeAmount', '75.0000')
            ->assertJsonPath('data.summary.netFeeAmount', '125.0000');

        $this->app['auth']->forgetGuards();

        $merchantResponse = $this->withToken($merchantToken, 'Bearer')
            ->getJson('/api/v1/portal/transactions?operation=C2B_USSD_PUSH')
            ->assertOk();

        $merchantResponse
            ->assertJsonPath('data.transactions.0.fee', '200.0000')
            ->assertJsonMissingPath('data.transactions.0.providerFee')
            ->assertJsonMissingPath('data.summary.providerFeeAmount')
            ->assertJsonMissingPath('data.summary.netFeeAmount')
            ->assertJsonPath('data.summary.feeAmount', '200.0000')
            ->assertJsonPath('data.summary.netAmount', '9800.0000');
    }

    /**
     * @return array{0: Merchant, 1: string}
     */
    private function createMerchantPortalSession(): array
    {
        $merchant = $this->createActiveMerchantWithCredentials()['merchant'];

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Portal Owner',
            'email' => 'owner-'.uniqid('', true).'@test.com',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return [$merchant, $user->createToken('merchant-dashboard')->plainTextToken];
    }

    private function adminToken(): string
    {
        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();

        return $admin->createToken('admin-dashboard')->plainTextToken;
    }

    private function createLegacyTransaction(
        Merchant $merchant,
        int $networkId,
        PaymentOperation $operation,
        string $amount,
        ?string $createdAt = null,
    ): Transaction {
        $at = $createdAt ?? '2026-09-15 10:00:00';

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-'.strtoupper(Str::random(12)),
            'merchant_id' => $merchant->id,
            'provider_network_id' => $networkId,
            'request_id' => (string) Str::uuid(),
            'reference' => 'REF-'.Str::random(8),
            'operation' => $operation,
            'status' => TransactionStatus::Success,
            'amount' => $amount,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'finalized_at' => $at,
        ]);

        Transaction::query()->whereKey($transaction->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $transaction->refresh();
    }

    private function creditDisbursementWallet(Merchant $merchant, string $providerCode, string $amount): Wallet
    {
        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->whereHas('providerNetwork', fn ($query) => $query->where('code', $providerCode))
            ->with('balance')
            ->firstOrFail();

        $wallet->balance->update([
            'available' => $amount,
            'reserved' => '0.0000',
            'total' => $amount,
        ]);

        return $wallet->refresh()->load('balance');
    }

    /**
     * @param  array{merchant: Merchant, client_id: string, client_secret: string}  $credentials
     * @param  array<string, mixed>  $overrides
     */
    private function postCollection(array $credentials, array $overrides = [])
    {
        $payload = array_merge([
            'requestId' => (string) Str::uuid(),
            'providerCode' => ProviderCode::Vodacom->value,
            'amount' => 10000.00,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'reference' => 'INV-'.Str::random(8),
            'callbackUrl' => 'https://merchant.test/callback',
            'narration' => 'Test collection',
        ], $overrides);

        $token = $this->getAccessToken($credentials['client_id'], $credentials['client_secret']);
        $body = json_encode($payload);
        $hmac = app(HmacSignatureService::class);
        $path = '/api/v1/payments/collections/push';
        $contentSha256 = $hmac->hashRequestBody($body);
        $canonical = $hmac->buildCanonicalString('POST', $path, $contentSha256);

        return $this->postJson($path, $payload, [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Signature' => $hmac->sign($canonical, $credentials['client_secret']),
        ]);
    }

    /**
     * @param  array{merchant: Merchant, client_id: string, client_secret: string}  $credentials
     * @param  array<string, mixed>  $overrides
     */
    private function postDisbursement(array $credentials, array $overrides = [])
    {
        $payload = array_merge([
            'requestId' => (string) Str::uuid(),
            'providerCode' => ProviderCode::Vodacom->value,
            'amount' => 1000.00,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'reference' => 'PAY-'.Str::random(8),
            'callbackUrl' => 'https://merchant.test/callback',
            'narration' => 'Test payout',
        ], $overrides);

        $token = $this->getAccessToken($credentials['client_id'], $credentials['client_secret']);
        $body = json_encode($payload);
        $hmac = app(HmacSignatureService::class);
        $path = '/api/v1/payments/disbursements';
        $contentSha256 = $hmac->hashRequestBody($body);
        $canonical = $hmac->buildCanonicalString('POST', $path, $contentSha256);

        return $this->postJson($path, $payload, [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Signature' => $hmac->sign($canonical, $credentials['client_secret']),
        ]);
    }
}
