<?php

namespace Tests\Feature;

use App\Enums\WalletTransferStatus;
use App\Enums\WalletType;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use Database\Seeders\GatewaySeeder;
use Tests\GatewayTestCase;

class WalletTransferTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_merchant_request_holds_funds_and_stays_pending(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '10000');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'VODACOM');
        $merchantTotalBefore = $this->merchantTotal($merchant);

        $response = $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 4000,
                'reference' => 'TRF-REF-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL')
            ->assertJsonPath('data.source', 'MERCHANT')
            ->assertJsonPath('data.amount', '4000.0000');

        $source->refresh()->load('balance');
        $destination->refresh()->load('balance');

        $this->assertSame('6000.0000', (string) $source->balance->available);
        $this->assertSame('4000.0000', (string) $source->balance->reserved);
        $this->assertSame('10000.0000', (string) $source->balance->total);
        $this->assertSame('0.0000', (string) $destination->balance->available);
        $this->assertSame($merchantTotalBefore, $this->merchantTotal($merchant));
    }

    public function test_approve_moves_funds_and_recomputes_ancestors(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        // Different providers so the two leaves sit under distinct provider totals.
        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '10000');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'AIRTEL');
        $merchantTotalBefore = $this->merchantTotal($merchant);

        $create = $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 4000,
            ]);

        $transferId = (int) $create->json('data.id');

        $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/wallet-transfers/{$transferId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $source->refresh()->load('balance');
        $destination->refresh()->load('balance');

        $this->assertSame('6000.0000', (string) $source->balance->available);
        $this->assertSame('0.0000', (string) $source->balance->reserved);
        $this->assertSame('6000.0000', (string) $source->balance->total);
        $this->assertSame('4000.0000', (string) $destination->balance->available);
        $this->assertSame('4000.0000', (string) $destination->balance->total);

        $sourceParent = Wallet::query()->with('balance')->findOrFail($source->parent_wallet_id);
        $destinationParent = Wallet::query()->with('balance')->findOrFail($destination->parent_wallet_id);

        $this->assertSame('6000.0000', (string) $sourceParent->balance->total);
        $this->assertSame('4000.0000', (string) $destinationParent->balance->total);
        $this->assertSame($merchantTotalBefore, $this->merchantTotal($merchant));
    }

    public function test_reject_restores_available_funds(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'YAS', '5000');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'YAS');

        $create = $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 2500,
            ]);

        $transferId = (int) $create->json('data.id');

        $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/wallet-transfers/{$transferId}/reject", [
                'reason' => 'Not needed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejectionReason', 'Not needed');

        $source->refresh()->load('balance');
        $destination->refresh()->load('balance');

        $this->assertSame('5000.0000', (string) $source->balance->available);
        $this->assertSame('0.0000', (string) $source->balance->reserved);
        $this->assertSame('0.0000', (string) $destination->balance->available);
    }

    public function test_cannot_approve_or_reject_twice(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'AIRTEL', '5000');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'AIRTEL');

        $create = $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 1000,
            ]);

        $transferId = (int) $create->json('data.id');

        $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/wallet-transfers/{$transferId}/approve")
            ->assertOk();

        $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/wallet-transfers/{$transferId}/approve")
            ->assertStatus(422);

        $this->withToken($adminToken, 'Bearer')
            ->postJson("/api/admin/v1/wallet-transfers/{$transferId}/reject")
            ->assertStatus(422);

        $this->assertSame(
            WalletTransferStatus::Approved,
            WalletTransfer::query()->findOrFail($transferId)->status,
        );
    }

    public function test_same_wallet_transfer_is_rejected(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '5000');

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $source->id,
                'amount' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_non_leaf_wallet_transfer_is_rejected(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '5000');

        $parent = $merchant->wallets()
            ->where('wallet_type', WalletType::MerchantParent)
            ->firstOrFail();

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $parent->id,
                'amount' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_cross_merchant_wallet_is_rejected(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        [$otherMerchant] = $this->createMerchantPortalSession();

        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '5000');
        $foreign = $this->leafWallet($otherMerchant, WalletType::DisbursementLeaf, 'VODACOM');

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $foreign->id,
                'amount' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_insufficient_funds_is_rejected(): void
    {
        [$merchant, $token] = $this->createMerchantPortalSession();
        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '500');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'VODACOM');

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 5000,
            ])
            ->assertStatus(422);

        $source->refresh()->load('balance');
        $this->assertSame('500.0000', (string) $source->balance->available);
        $this->assertSame(0, WalletTransfer::query()->count());
    }

    public function test_admin_instant_transfer_settles_immediately(): void
    {
        [$merchant] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'HALOTEL', '8000');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'HALOTEL');
        $merchantTotalBefore = $this->merchantTotal($merchant);

        $this->withToken($adminToken, 'Bearer')
            ->postJson('/api/admin/v1/wallet-transfers', [
                'merchantId' => $merchant->id,
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 3000,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.source', 'ADMIN');

        $source->refresh()->load('balance');
        $destination->refresh()->load('balance');

        $this->assertSame('5000.0000', (string) $source->balance->available);
        $this->assertSame('0.0000', (string) $source->balance->reserved);
        $this->assertSame('3000.0000', (string) $destination->balance->available);
        $this->assertSame($merchantTotalBefore, $this->merchantTotal($merchant));
    }

    private function leafWallet(Merchant $merchant, WalletType $type, string $providerCode): Wallet
    {
        return $merchant->wallets()
            ->where('wallet_type', $type)
            ->whereHas('providerNetwork', fn ($query) => $query->where('code', $providerCode))
            ->with('balance')
            ->firstOrFail();
    }

    private function fundWallet(Merchant $merchant, WalletType $type, string $providerCode, string $amount): Wallet
    {
        $wallet = $this->leafWallet($merchant, $type, $providerCode);
        $normalized = number_format((float) $amount, 4, '.', '');

        $wallet->balance->update([
            'available' => bcadd((string) $wallet->balance->available, $normalized, 4),
            'total' => bcadd((string) $wallet->balance->total, $normalized, 4),
        ]);

        $this->rebuildAncestors($wallet);

        return $wallet->refresh()->load('balance');
    }

    private function rebuildAncestors(Wallet $wallet): void
    {
        $parentId = $wallet->parent_wallet_id;

        while ($parentId !== null) {
            $parent = Wallet::query()->with(['balance', 'childWallets.balance'])->findOrFail($parentId);

            $available = '0.0000';
            $reserved = '0.0000';
            $total = '0.0000';

            foreach ($parent->childWallets as $child) {
                if ($child->balance === null) {
                    continue;
                }

                $available = bcadd($available, (string) $child->balance->available, 4);
                $reserved = bcadd($reserved, (string) $child->balance->reserved, 4);
                $total = bcadd($total, (string) $child->balance->total, 4);
            }

            $parent->balance?->update([
                'available' => $available,
                'reserved' => $reserved,
                'total' => $total,
            ]);

            $parentId = $parent->parent_wallet_id;
        }
    }

    private function merchantTotal(Merchant $merchant): string
    {
        $total = '0.0000';

        $leaves = $merchant->wallets()
            ->whereIn('wallet_type', [WalletType::CollectionLeaf, WalletType::DisbursementLeaf])
            ->with('balance')
            ->get();

        foreach ($leaves as $leaf) {
            $total = bcadd($total, (string) ($leaf->balance?->total ?? '0'), 4);
        }

        return $total;
    }

    /**
     * @return array{0: Merchant, 1: string}
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
