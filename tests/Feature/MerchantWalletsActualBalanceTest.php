<?php

namespace Tests\Feature;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class MerchantWalletsActualBalanceTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_wallets_include_lifetime_collections_payouts_and_fees(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();

        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::C2bPush],
            ['commission_type' => CommissionType::Percent, 'value' => '2'],
        );
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::B2cDisbursement],
            ['commission_type' => CommissionType::Fixed, 'value' => '600'],
        );

        $this->createTransaction($merchant, PaymentOperation::C2bPush, TransactionStatus::Success, '100000');
        $this->createTransaction($merchant, PaymentOperation::C2bPush, TransactionStatus::Success, '200000');
        $this->createTransaction($merchant, PaymentOperation::C2bPush, TransactionStatus::Failed, '50000');
        $this->createTransaction($merchant, PaymentOperation::B2cDisbursement, TransactionStatus::Success, '10000');

        $this->withToken($token, 'Bearer')
            ->getJson('/api/v1/portal/wallets')
            ->assertOk()
            ->assertJsonPath('data.actualBalance', '300000.0000')
            ->assertJsonPath('data.transactionFee', '6000.0000')
            ->assertJsonPath('data.totalPayout', '10000.0000')
            ->assertJsonPath('data.totalPayoutCharges', '600.0000')
            ->assertJsonStructure([
                'data' => [
                    'wallets',
                    'actualBalance',
                    'transactionFee',
                    'totalPayout',
                    'totalPayoutCharges',
                    'currency',
                ],
            ]);
    }

    private function createTransaction(
        Merchant $merchant,
        PaymentOperation $operation,
        TransactionStatus $status,
        string $amount,
    ): void {
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
