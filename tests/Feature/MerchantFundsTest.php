<?php

namespace Tests\Feature;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Enums\SettlementRequestStatus;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\SettlementRequest;
use App\Models\Transaction;
use App\Models\Wallet;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class MerchantFundsTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_lists_collected_charges_settled_and_net_remaining_per_merchant(): void
    {
        $alpha = $this->createMerchant('Alpha Shop');
        $beta = $this->createMerchant('Beta Store');

        MerchantCommission::query()->create([
            'merchant_id' => $alpha->id,
            'operation' => PaymentOperation::C2bPush,
            'commission_type' => CommissionType::Percent,
            'value' => '2',
        ]);

        $voda = $this->collectionWallet($alpha, 'VODACOM');
        $yas = $this->collectionWallet($alpha, 'YAS');

        $this->createTransaction($alpha, $voda, PaymentOperation::C2bPush, TransactionStatus::Success, '10000');
        $this->createTransaction($alpha, $voda, PaymentOperation::C2bPush, TransactionStatus::Success, '5000');
        $this->createTransaction($alpha, $voda, PaymentOperation::C2bPush, TransactionStatus::Failed, '7000');
        $this->createTransaction($alpha, $yas, PaymentOperation::C2bPush, TransactionStatus::Success, '2000');
        $this->createTransaction($alpha, $voda, PaymentOperation::B2cDisbursement, TransactionStatus::Success, '3000');

        $this->createSettlement($alpha, $voda, SettlementRequestStatus::Approved, '6000');
        $this->createSettlement($alpha, $voda, SettlementRequestStatus::PendingApproval, '1000');
        $this->createSettlement($alpha, $voda, SettlementRequestStatus::Rejected, '500');

        $voda->balance->update(['available' => '8000', 'reserved' => '1000', 'total' => '9000']);

        $response = $this->withToken($this->adminToken(), 'Bearer')
            ->getJson('/api/admin/v1/reports/merchant-funds')
            ->assertOk();

        $row = collect($response->json('data.merchants'))->firstWhere('merchantId', $alpha->id);

        $this->assertSame(3, $row['collectionCount']);
        $this->assertSame('17000.0000', $row['collected']);
        $this->assertSame('340.0000', $row['charges']);
        $this->assertSame('6000.0000', $row['settled']);
        $this->assertSame('1000.0000', $row['pendingSettlement']);
        $this->assertSame('10660.0000', $row['netRemaining']);
        $this->assertSame('9000.0000', $row['walletBalance']);

        $empty = collect($response->json('data.merchants'))->firstWhere('merchantId', $beta->id);
        $this->assertSame('0.0000', $empty['collected']);
        $this->assertSame('0.0000', $empty['netRemaining']);

        $response->assertJsonPath('data.totals.collected', '17000.0000')
            ->assertJsonPath('data.totals.netRemaining', '10660.0000');
    }

    public function test_filters_by_provider_and_search(): void
    {
        $alpha = $this->createMerchant('Alpha Shop');
        $this->createMerchant('Beta Store');

        MerchantCommission::query()->create([
            'merchant_id' => $alpha->id,
            'operation' => PaymentOperation::C2bPush,
            'commission_type' => CommissionType::Percent,
            'value' => '2',
        ]);

        $voda = $this->collectionWallet($alpha, 'VODACOM');
        $yas = $this->collectionWallet($alpha, 'YAS');

        $this->createTransaction($alpha, $voda, PaymentOperation::C2bPush, TransactionStatus::Success, '10000');
        $this->createTransaction($alpha, $yas, PaymentOperation::C2bPush, TransactionStatus::Success, '2000');
        $this->createSettlement($alpha, $voda, SettlementRequestStatus::Approved, '6000');

        $response = $this->withToken($this->adminToken(), 'Bearer')
            ->getJson('/api/admin/v1/reports/merchant-funds?providerCode=YAS&search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data.merchants');

        $this->assertSame('2000.0000', $response->json('data.merchants.0.collected'));
        $this->assertSame('40.0000', $response->json('data.merchants.0.charges'));
        $this->assertSame('0.0000', $response->json('data.merchants.0.settled'));
        $this->assertSame('1960.0000', $response->json('data.merchants.0.netRemaining'));
    }

    private function createMerchant(string $name): Merchant
    {
        $merchant = $this->createActiveMerchantWithCredentials()['merchant'];
        $merchant->update(['name' => $name]);

        return $merchant->refresh();
    }

    private function collectionWallet(Merchant $merchant, string $providerCode): Wallet
    {
        return $merchant->wallets()
            ->where('wallet_type', WalletType::CollectionLeaf)
            ->whereHas('providerNetwork', fn ($query) => $query->where('code', $providerCode))
            ->with('balance')
            ->firstOrFail();
    }

    private function createTransaction(
        Merchant $merchant,
        Wallet $wallet,
        PaymentOperation $operation,
        TransactionStatus $status,
        string $amount,
    ): void {
        Transaction::query()->create([
            'transaction_id' => 'TXN-'.strtoupper(Str::random(12)),
            'merchant_id' => $merchant->id,
            'provider_network_id' => $wallet->provider_network_id,
            'request_id' => (string) Str::uuid(),
            'reference' => 'INV-'.Str::random(8),
            'operation' => $operation,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
        ]);
    }

    private function createSettlement(Merchant $merchant, Wallet $wallet, SettlementRequestStatus $status, string $amount): void
    {
        SettlementRequest::query()->create([
            'request_id' => 'STR-'.strtoupper(Str::random(16)),
            'merchant_id' => $merchant->id,
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'commission_amount' => '0',
            'total_debit' => $amount,
            'currency' => 'TZS',
            'status' => $status,
        ]);
    }

    private function adminToken(): string
    {
        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();

        return $admin->createToken('admin-dashboard')->plainTextToken;
    }
}
