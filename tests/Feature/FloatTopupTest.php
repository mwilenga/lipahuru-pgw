<?php

namespace Tests\Feature;

use App\Enums\FloatTopupStatus;
use App\Enums\WalletType;
use App\Models\AdminUser;
use App\Models\FloatTopup;
use App\Models\MerchantUser;
use App\Models\ProviderNetwork;
use Database\Seeders\GatewaySeeder;
use Tests\GatewayTestCase;

class FloatTopupTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_merchant_can_request_pending_float_topup_without_crediting_balance(): void
    {
        [$merchant, $merchantToken] = $this->createMerchantPortalSession();
        $network = ProviderNetwork::query()->where('code', 'VODACOM')->firstOrFail();

        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->where('provider_network_id', $network->id)
            ->with('balance')
            ->firstOrFail();

        $before = (string) $wallet->balance->available;

        $response = $this->withToken($merchantToken, 'Bearer')
            ->postJson('/api/v1/portal/float-topups', [
                'items' => [
                    ['providerCode' => 'VODACOM', 'amount' => 5000],
                    ['providerCode' => 'AIRTEL', 'amount' => 2000],
                ],
                'reference' => 'BANK-REF-001',
                'notes' => 'Weekend float',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.source', 'MERCHANT')
            ->assertJsonPath('data.totalAmount', '7000.0000');

        $wallet->refresh()->load('balance');
        $this->assertSame($before, (string) $wallet->balance->available);
        $this->assertSame(1, FloatTopup::query()->count());
    }

    public function test_admin_approve_credits_disbursement_leaf_and_parents(): void
    {
        [$merchant, $merchantToken] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $create = $this->withToken($merchantToken, 'Bearer')
            ->postJson('/api/v1/portal/float-topups', [
                'items' => [
                    ['providerCode' => 'VODACOM', 'amount' => 1000],
                ],
            ]);

        $create->assertOk();
        $topupId = (int) $create->json('data.id');

        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->whereHas('providerNetwork', fn ($q) => $q->where('code', 'VODACOM'))
            ->with(['balance', 'parentWallet.balance', 'parentWallet.parentWallet.balance'])
            ->firstOrFail();

        $providerTotal = $wallet->parentWallet;
        $merchantParent = $providerTotal?->parentWallet;

        $approve = $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/float-topups/{$topupId}/approve");

        $approve->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $wallet->refresh()->load('balance');
        $providerTotal?->refresh()->load('balance');
        $merchantParent?->refresh()->load('balance');

        $this->assertSame('1000.0000', (string) $wallet->balance->available);
        $this->assertSame('1000.0000', (string) $wallet->balance->total);
        $this->assertSame('1000.0000', (string) $providerTotal?->balance?->available);
        $this->assertSame('1000.0000', (string) $merchantParent?->balance?->available);
    }

    public function test_admin_reject_does_not_credit_balance(): void
    {
        [$merchant, $merchantToken] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $create = $this->withToken($merchantToken, 'Bearer')
            ->postJson('/api/v1/portal/float-topups', [
                'items' => [
                    ['providerCode' => 'YAS', 'amount' => 1500],
                ],
            ]);

        $topupId = (int) $create->json('data.id');

        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->whereHas('providerNetwork', fn ($q) => $q->where('code', 'YAS'))
            ->with('balance')
            ->firstOrFail();

        $reject = $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/float-topups/{$topupId}/reject", [
                'reason' => 'Payment not received',
            ]);

        $reject->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejectionReason', 'Payment not received');

        $wallet->refresh()->load('balance');
        $this->assertSame('0.0000', (string) $wallet->balance->available);
    }

    public function test_admin_direct_topup_credits_immediately(): void
    {
        [$merchant] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $response = $this->withToken($adminToken, 'Bearer')
            ->postJson('/api/admin/v1/float-topups', [
                'merchantId' => $merchant->id,
                'items' => [
                    ['providerCode' => 'HALOTEL', 'amount' => 2500],
                ],
                'reference' => 'ADMIN-DIRECT-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.source', 'ADMIN')
            ->assertJsonPath('data.totalAmount', '2500.0000');

        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->whereHas('providerNetwork', fn ($q) => $q->where('code', 'HALOTEL'))
            ->with('balance')
            ->firstOrFail();

        $this->assertSame('2500.0000', (string) $wallet->balance->available);
    }

    public function test_cannot_approve_twice(): void
    {
        [, $merchantToken] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $create = $this->withToken($merchantToken, 'Bearer')
            ->postJson('/api/v1/portal/float-topups', [
                'items' => [
                    ['providerCode' => 'AIRTEL', 'amount' => 1000],
                ],
            ]);

        $topupId = (int) $create->json('data.id');

        $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/float-topups/{$topupId}/approve")
            ->assertOk();

        $second = $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/float-topups/{$topupId}/approve");

        $second->assertStatus(422);
        $this->assertSame(FloatTopupStatus::Approved, FloatTopup::query()->findOrFail($topupId)->status);
    }

    /**
     * @return array{0: \App\Models\Merchant, 1: string}
     */
    private function createMerchantPortalSession(): array
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Portal Owner',
            'email' => 'owner-'.uniqid('', true).'@test.com',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('merchant-dashboard')->plainTextToken;

        return [$merchant, $token];
    }

    private function adminToken(): string
    {
        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();

        return $admin->createToken('admin-dashboard')->plainTextToken;
    }
}
