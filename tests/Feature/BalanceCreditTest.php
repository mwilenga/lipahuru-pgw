<?php

namespace Tests\Feature;

use App\Enums\WalletType;
use App\Models\AdminUser;
use App\Models\BalanceCredit;
use Database\Seeders\GatewaySeeder;
use Tests\GatewayTestCase;

class BalanceCreditTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_admin_can_credit_merchant_balance(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];
        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();
        $token = $admin->createToken('admin-dashboard')->plainTextToken;

        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::MerchantBalance)
            ->with('balance')
            ->firstOrFail();

        $this->assertSame('0.0000', (string) $wallet->balance->available);

        $response = $this->withToken($token, 'Bearer')
            ->postJson('/api/admin/v1/balance-credits', [
                'merchantId' => $merchant->id,
                'amount' => 5000,
                'reference' => 'ADMIN-CR-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.amount', '5000.0000')
            ->assertJsonPath('data.reference', 'ADMIN-CR-1');

        $wallet->refresh()->load('balance');
        $this->assertSame('5000.0000', (string) $wallet->balance->available);
        $this->assertSame('5000.0000', (string) $wallet->balance->total);
        $this->assertSame(1, BalanceCredit::query()->count());
    }
}
