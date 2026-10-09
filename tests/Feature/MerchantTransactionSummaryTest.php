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

class MerchantTransactionSummaryTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_collection_summary_shows_charges_and_net_for_successful_only(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCommission($merchant, PaymentOperation::C2bPush, CommissionType::Percent, '2');
        $this->setCommission($merchant, PaymentOperation::B2cDisbursement, CommissionType::Fixed, '500');

        $this->createTransaction($merchant, PaymentOperation::C2bPush, TransactionStatus::Success, '10000');
        $this->createTransaction($merchant, PaymentOperation::C2bPush, TransactionStatus::Success, '5000');
        $this->createTransaction($merchant, PaymentOperation::C2bPush, TransactionStatus::Failed, '7000');
        $this->createTransaction($merchant, PaymentOperation::B2cDisbursement, TransactionStatus::Success, '3000');

        $this->withToken($token, 'Bearer')
            ->getJson('/api/v1/portal/transactions?operation=C2B_USSD_PUSH')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 3)
            ->assertJsonPath('data.summary.totalAmount', '22000.0000')
            ->assertJsonPath('data.summary.successCount', 2)
            ->assertJsonPath('data.summary.successAmount', '15000.0000')
            ->assertJsonPath('data.summary.feeAmount', '300.0000')
            ->assertJsonPath('data.summary.netAmount', '14700.0000');
    }

    public function test_disbursement_summary_caps_fixed_fee_at_amount(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCommission($merchant, PaymentOperation::B2cDisbursement, CommissionType::Fixed, '500');

        $this->createTransaction($merchant, PaymentOperation::B2cDisbursement, TransactionStatus::Success, '3000');
        $this->createTransaction($merchant, PaymentOperation::B2cDisbursement, TransactionStatus::Success, '200');

        $this->withToken($token, 'Bearer')
            ->getJson('/api/v1/portal/transactions?operation=B2C_DISBURSEMENT')
            ->assertOk()
            ->assertJsonPath('data.summary.successAmount', '3200.0000')
            ->assertJsonPath('data.summary.feeAmount', '700.0000')
            ->assertJsonPath('data.summary.netAmount', '2500.0000');
    }

    public function test_admin_summary_applies_each_merchants_commission(): void
    {
        [$first] = $this->createMerchantPortalSession();
        [$second] = $this->createMerchantPortalSession();
        $this->setCommission($first, PaymentOperation::C2bPush, CommissionType::Percent, '2');
        $this->setCommission($second, PaymentOperation::C2bPush, CommissionType::Fixed, '500');

        $this->createTransaction($first, PaymentOperation::C2bPush, TransactionStatus::Success, '10000');
        $this->createTransaction($second, PaymentOperation::C2bPush, TransactionStatus::Success, '8000');
        $this->createTransaction($second, PaymentOperation::C2bPush, TransactionStatus::Success, '300');
        $this->createTransaction($second, PaymentOperation::C2bPush, TransactionStatus::PendingFinal, '9000');

        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();

        $this->withToken($admin->createToken('admin-dashboard')->plainTextToken, 'Bearer')
            ->getJson('/api/admin/v1/transactions?operation=C2B_USSD_PUSH')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 4)
            ->assertJsonPath('data.summary.totalAmount', '27300.0000')
            ->assertJsonPath('data.summary.successCount', 3)
            ->assertJsonPath('data.summary.successAmount', '18300.0000')
            ->assertJsonPath('data.summary.feeAmount', '1000.0000')
            ->assertJsonPath('data.summary.netAmount', '17300.0000');
    }

    private function setCommission(Merchant $merchant, PaymentOperation $operation, CommissionType $type, string $value): void
    {
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => $operation],
            ['commission_type' => $type, 'value' => $value],
        );
    }

    private function createTransaction(Merchant $merchant, PaymentOperation $operation, TransactionStatus $status, string $amount): void
    {
        Transaction::query()->create([
            'transaction_id' => 'TXN-'.strtoupper(Str::random(12)),
            'merchant_id' => $merchant->id,
            'provider_network_id' => $merchant->wallets()->whereNotNull('provider_network_id')->value('provider_network_id'),
            'request_id' => (string) Str::uuid(),
            'reference' => 'INV-'.Str::random(8),
            'operation' => $operation,
            'status' => $status,
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
}
