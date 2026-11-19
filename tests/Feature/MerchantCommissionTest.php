<?php

namespace Tests\Feature;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class MerchantCommissionTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_percent_commission_above_100_is_rejected(): void
    {
        $merchant = $this->createActiveMerchantWithCredentials()['merchant'];

        $this->withToken($this->adminToken(), 'Bearer')
            ->putJson("/api/admin/v1/merchants/{$merchant->id}/commissions", [
                'commissions' => [
                    [
                        'operation' => PaymentOperation::C2bPush->value,
                        'commissionType' => CommissionType::Percent->value,
                        'value' => '1.3',
                    ],
                    [
                        'operation' => PaymentOperation::B2cDisbursement->value,
                        'commissionType' => CommissionType::Percent->value,
                        'value' => '600',
                    ],
                ],
            ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'PGW-1001')
            ->assertJsonPath('message', 'Percent commission value may not be greater than 100.')
            ->assertJsonFragment([
                'commissions.1.value' => ['Percent commission value may not be greater than 100.'],
            ]);
    }

    public function test_fixed_commission_above_100_is_allowed(): void
    {
        $merchant = $this->createActiveMerchantWithCredentials()['merchant'];

        $this->withToken($this->adminToken(), 'Bearer')
            ->putJson("/api/admin/v1/merchants/{$merchant->id}/commissions", [
                'commissions' => [
                    [
                        'operation' => PaymentOperation::C2bPush->value,
                        'commissionType' => CommissionType::Percent->value,
                        'value' => '1.3',
                    ],
                    [
                        'operation' => PaymentOperation::B2cDisbursement->value,
                        'commissionType' => CommissionType::Fixed->value,
                        'value' => '600',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commissions.1.commissionType', 'FIXED')
            ->assertJsonPath('data.commissions.1.value', '600.0000');
    }

    public function test_percent_100_fees_full_amount_while_percent_2_does_not(): void
    {
        [$full, $tokenFull] = $this->createMerchantPortalSession();
        [$partial, $tokenPartial] = $this->createMerchantPortalSession();

        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $full->id, 'operation' => PaymentOperation::B2cDisbursement],
            ['commission_type' => CommissionType::Percent, 'value' => '100'],
        );
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $partial->id, 'operation' => PaymentOperation::B2cDisbursement],
            ['commission_type' => CommissionType::Percent, 'value' => '2'],
        );

        $this->createTransaction($full, '10000');
        $this->createTransaction($partial, '10000');

        $this->withToken($tokenFull, 'Bearer')
            ->getJson('/api/v1/portal/transactions?operation=B2C_DISBURSEMENT')
            ->assertOk()
            ->assertJsonPath('data.summary.successAmount', '10000.0000')
            ->assertJsonPath('data.summary.feeAmount', '10000.0000')
            ->assertJsonPath('data.summary.netAmount', '0.0000');

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenPartial, 'Bearer')
            ->getJson('/api/v1/portal/transactions?operation=B2C_DISBURSEMENT')
            ->assertOk()
            ->assertJsonPath('data.summary.successAmount', '10000.0000')
            ->assertJsonPath('data.summary.feeAmount', '200.0000')
            ->assertJsonPath('data.summary.netAmount', '9800.0000');
    }

    public function test_migration_converts_b2c_percent_over_100_to_fixed(): void
    {
        $merchant = $this->createActiveMerchantWithCredentials()['merchant'];

        MerchantCommission::query()->create([
            'merchant_id' => $merchant->id,
            'operation' => PaymentOperation::B2cDisbursement,
            'commission_type' => CommissionType::Percent,
            'value' => '600',
        ]);
        MerchantCommission::query()->create([
            'merchant_id' => $merchant->id,
            'operation' => PaymentOperation::C2bPush,
            'commission_type' => CommissionType::Percent,
            'value' => '150',
        ]);

        $migration = require database_path('migrations/2026_11_19_000001_fix_b2c_percent_over_100_to_fixed.php');
        $migration->up();

        $this->assertSame(
            CommissionType::Fixed,
            MerchantCommission::query()
                ->where('merchant_id', $merchant->id)
                ->where('operation', PaymentOperation::B2cDisbursement)
                ->firstOrFail()
                ->commission_type,
        );
        $this->assertSame(
            '600.0000',
            (string) MerchantCommission::query()
                ->where('merchant_id', $merchant->id)
                ->where('operation', PaymentOperation::B2cDisbursement)
                ->value('value'),
        );
        $this->assertSame(
            CommissionType::Percent,
            MerchantCommission::query()
                ->where('merchant_id', $merchant->id)
                ->where('operation', PaymentOperation::C2bPush)
                ->firstOrFail()
                ->commission_type,
        );
    }

    private function createTransaction(Merchant $merchant, string $amount): void
    {
        Transaction::query()->create([
            'transaction_id' => 'TXN-'.strtoupper(Str::random(12)),
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->wallets()->whereNotNull('provider_network_id')->value('provider_network_id'),
            'request_id' => (string) Str::uuid(),
            'reference' => 'INV-'.Str::random(8),
            'operation' => PaymentOperation::B2cDisbursement,
            'status' => TransactionStatus::Success,
            'amount' => $amount,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
        ]);
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
}
