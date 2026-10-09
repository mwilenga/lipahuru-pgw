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
use App\Models\MerchantUser;
use App\Models\SettlementRequest;
use App\Models\Transaction;
use App\Models\Wallet;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class SettlementRequestTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_wallets_show_balance_after_outstanding_collection_commission(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCollectionCommission($merchant, CommissionType::Percent, '2');
        $wallet = $this->collectWallet($merchant, 'VODACOM', ['6000', '4000']);

        $response = $this->withToken($token, 'Bearer')
            ->getJson('/api/v1/portal/settlements/wallets')
            ->assertOk()
            ->assertJsonPath('data.commission.type', 'PERCENT')
            ->assertJsonPath('data.bankAccount.accountNumber', '0150123456789');

        $row = collect($response->json('data.wallets'))->firstWhere('walletId', $wallet->id);

        $this->assertSame('VODACOM', $row['providerCode']);
        $this->assertSame('10000.0000', $row['available']);
        $this->assertSame('200.0000', $row['commissionOutstanding']);
        $this->assertSame('9800.0000', $row['settleable']);

        $types = collect($response->json('data.wallets'))->pluck('walletId')
            ->map(fn (int $id) => Wallet::query()->findOrFail($id)->wallet_type)
            ->unique()
            ->values()
            ->all();
        $this->assertSame([WalletType::CollectionLeaf], $types);
    }

    public function test_fixed_commission_is_capped_at_each_collection_amount(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCollectionCommission($merchant, CommissionType::Fixed, '500');
        $wallet = $this->collectWallet($merchant, 'YAS', ['300', '10000']);

        $row = collect(
            $this->withToken($token, 'Bearer')->getJson('/api/v1/portal/settlements/wallets')->json('data.wallets'),
        )->firstWhere('walletId', $wallet->id);

        $this->assertSame('800.0000', $row['commissionOutstanding']);
        $this->assertSame('9500.0000', $row['settleable']);
    }

    public function test_request_holds_amount_plus_commission_and_claims_commission_once(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCollectionCommission($merchant, CommissionType::Percent, '2');
        $wallet = $this->collectWallet($merchant, 'VODACOM', ['10000']);

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', [
                'walletId' => $wallet->id,
                'amount' => 5000,
                'memo' => 'Weekly payout',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL')
            ->assertJsonPath('data.amount', '5000.0000')
            ->assertJsonPath('data.commissionAmount', '200.0000')
            ->assertJsonPath('data.totalDebit', '5200.0000')
            ->assertJsonPath('data.bankAccountNumber', '0150123456789');

        $wallet->refresh()->load('balance');
        $this->assertSame('4800.0000', (string) $wallet->balance->available);
        $this->assertSame('5200.0000', (string) $wallet->balance->reserved);

        $row = collect(
            $this->withToken($token, 'Bearer')->getJson('/api/v1/portal/settlements/wallets')->json('data.wallets'),
        )->firstWhere('walletId', $wallet->id);

        $this->assertSame('0.0000', $row['commissionOutstanding']);
        $this->assertSame('4800.0000', $row['settleable']);

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $wallet->id, 'amount' => 4800])
            ->assertOk()
            ->assertJsonPath('data.commissionAmount', '0.0000')
            ->assertJsonPath('data.totalDebit', '4800.0000');
    }

    public function test_approve_debits_wallet_and_parent_totals(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCollectionCommission($merchant, CommissionType::Percent, '1');
        $wallet = $this->collectWallet($merchant, 'AIRTEL', ['10000']);

        $id = (int) $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $wallet->id, 'amount' => 4000])
            ->json('data.id');

        $this->withToken($this->adminToken(), 'Bearer')
            ->postJson("/api/admin/v1/settlement-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $wallet->refresh()->load('balance');
        $this->assertSame('5900.0000', (string) $wallet->balance->available);
        $this->assertSame('0.0000', (string) $wallet->balance->reserved);
        $this->assertSame('5900.0000', (string) $wallet->balance->total);

        $parent = Wallet::query()->with('balance')->findOrFail($wallet->parent_wallet_id);
        $this->assertSame('5900.0000', (string) $parent->balance->total);

        $this->withToken($this->adminToken(), 'Bearer')
            ->postJson("/api/admin/v1/settlement-requests/{$id}/reject")
            ->assertStatus(422);
    }

    public function test_reject_and_cancel_release_funds_and_commission(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCollectionCommission($merchant, CommissionType::Percent, '2');
        $wallet = $this->collectWallet($merchant, 'HALOTEL', ['10000']);

        $first = (int) $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $wallet->id, 'amount' => 3000])
            ->json('data.id');

        $this->withToken($this->adminToken(), 'Bearer')
            ->postJson("/api/admin/v1/settlement-requests/{$first}/reject", ['reason' => 'Bank details unclear'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejectionReason', 'Bank details unclear');

        $second = (int) $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $wallet->id, 'amount' => 3000])
            ->assertJsonPath('data.commissionAmount', '200.0000')
            ->json('data.id');

        $this->withToken($token, 'Bearer')
            ->postJson("/api/v1/portal/settlements/{$second}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $wallet->refresh()->load('balance');
        $this->assertSame('10000.0000', (string) $wallet->balance->available);
        $this->assertSame('0.0000', (string) $wallet->balance->reserved);
    }

    public function test_amount_above_settleable_balance_is_rejected(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $this->setCollectionCommission($merchant, CommissionType::Percent, '2');
        $wallet = $this->collectWallet($merchant, 'VODACOM', ['10000']);

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $wallet->id, 'amount' => 9900])
            ->assertStatus(422);

        $this->assertSame(0, SettlementRequest::query()->count());
    }

    public function test_request_requires_bank_account_and_collection_wallet(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $collection = $this->collectWallet($merchant, 'VODACOM', ['10000']);
        $disbursement = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->firstOrFail();

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $disbursement->id, 'amount' => 1000])
            ->assertStatus(422);

        $merchant->update(['settlement_account_number' => null]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $collection->id, 'amount' => 1000])
            ->assertStatus(422);

        $this->assertSame(0, SettlementRequest::query()->count());
    }

    public function test_merchant_cannot_cancel_another_merchants_request(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        [, $otherToken] = $this->createMerchantPortalSession();
        $wallet = $this->collectWallet($merchant, 'YAS', ['5000']);

        $id = (int) $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/settlements', ['walletId' => $wallet->id, 'amount' => 1000])
            ->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken, 'Bearer')
            ->postJson("/api/v1/portal/settlements/{$id}/cancel")
            ->assertNotFound();

        $this->assertSame(
            SettlementRequestStatus::PendingApproval,
            SettlementRequest::query()->findOrFail($id)->status,
        );
    }

    private function setCollectionCommission(Merchant $merchant, CommissionType $type, string $value): void
    {
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::C2bPush],
            ['commission_type' => $type, 'value' => $value],
        );
    }

    /**
     * Record successful collections on a network and credit the collection wallet.
     *
     * @param  list<string>  $amounts
     */
    private function collectWallet(Merchant $merchant, string $providerCode, array $amounts): Wallet
    {
        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::CollectionLeaf)
            ->whereHas('providerNetwork', fn ($query) => $query->where('code', $providerCode))
            ->with('balance')
            ->firstOrFail();

        $total = '0.0000';

        foreach ($amounts as $amount) {
            Transaction::query()->create([
                'transaction_id' => 'TXN-'.strtoupper(Str::random(12)),
                'merchant_id' => $merchant->id,
                'provider_network_id' => $wallet->provider_network_id,
                'request_id' => (string) Str::uuid(),
                'reference' => 'INV-'.Str::random(8),
                'operation' => PaymentOperation::C2bPush,
                'status' => TransactionStatus::Success,
                'amount' => $amount,
                'currency' => 'TZS',
                'msisdn' => '255754123456',
            ]);

            $total = bcadd($total, $amount, 4);
        }

        $wallet->balance->update([
            'available' => bcadd((string) $wallet->balance->available, $total, 4),
            'total' => bcadd((string) $wallet->balance->total, $total, 4),
        ]);

        $parentId = $wallet->parent_wallet_id;

        while ($parentId !== null) {
            $parent = Wallet::query()->with('balance')->findOrFail($parentId);
            $parent->balance?->update([
                'available' => bcadd((string) $parent->balance->available, $total, 4),
                'total' => bcadd((string) $parent->balance->total, $total, 4),
            ]);
            $parentId = $parent->parent_wallet_id;
        }

        return $wallet->refresh()->load('balance');
    }

    /**
     * @return array{0: Merchant, 1: string}
     */
    private function createMerchantPortalSession(): array
    {
        $merchant = $this->createActiveMerchantWithCredentials()['merchant'];
        $merchant->update([
            'settlement_bank_name' => 'CRDB Bank',
            'settlement_account_name' => 'Test Merchant Ltd',
            'settlement_account_number' => '0150123456789',
            'settlement_bank_branch' => 'Azikiwe',
        ]);

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Portal Owner',
            'email' => 'owner-'.uniqid('', true).'@test.com',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return [$merchant->refresh(), $user->createToken('merchant-dashboard')->plainTextToken];
    }

    private function adminToken(): string
    {
        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();

        return $admin->createToken('admin-dashboard')->plainTextToken;
    }
}
