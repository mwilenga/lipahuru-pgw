<?php

namespace Tests\Feature;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Models\Transaction;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletLedgerService;
use Tests\GatewayTestCase;

class WalletBalanceTest extends GatewayTestCase
{
    public function test_collection_credit_updates_parent_and_grandparent_wallets(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $collectionWallet = $merchant->wallets()
            ->where('wallet_type', WalletType::CollectionLeaf)
            ->with(['balance', 'parentWallet.balance', 'parentWallet.parentWallet.balance'])
            ->firstOrFail();

        $providerTotal = $collectionWallet->parentWallet;
        $merchantParent = $providerTotal?->parentWallet;

        $this->assertNotNull($providerTotal);
        $this->assertNotNull($merchantParent);

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-WALLET-TEST-001',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $collectionWallet->provider_network_id,
            'request_id' => 'req-wallet-001',
            'reference' => 'INV-WALLET-001',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 100,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
        ]);

        app(PaymentService::class)->finalizeSuccess($transaction);

        $collectionWallet->refresh()->load('balance');
        $providerTotal->refresh()->load('balance');
        $merchantParent->refresh()->load('balance');

        $this->assertSame('100.0000', (string) $collectionWallet->balance->total);
        $this->assertSame('100.0000', (string) $providerTotal->balance->total);
        $this->assertSame('100.0000', (string) $merchantParent->balance->total);
    }

    public function test_sync_parent_wallet_balances_reconciles_existing_child_totals(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $collectionWallet = $merchant->wallets()
            ->where('wallet_type', WalletType::CollectionLeaf)
            ->with(['balance', 'parentWallet.balance', 'parentWallet.parentWallet.balance'])
            ->firstOrFail();

        $providerTotal = $collectionWallet->parentWallet;
        $merchantParent = $providerTotal?->parentWallet;

        $collectionWallet->balance->update(['available' => 100, 'total' => 100]);
        $providerTotal?->balance?->update(['available' => 100, 'total' => 100]);
        $merchantParent?->balance?->update(['available' => 0, 'total' => 0]);

        $updated = app(WalletLedgerService::class)->syncParentWalletBalances($merchant->id);

        $this->assertSame(1, $updated);
        $this->assertSame('100.0000', (string) $merchantParent?->fresh()->balance?->total);
    }

    public function test_sync_hierarchy_repairs_stale_provider_total(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $collectionWallet = $merchant->wallets()
            ->where('wallet_type', WalletType::CollectionLeaf)
            ->with(['balance', 'parentWallet.balance', 'parentWallet.parentWallet.balance'])
            ->firstOrFail();

        $providerTotal = $collectionWallet->parentWallet;
        $merchantParent = $providerTotal?->parentWallet;

        $collectionWallet->balance->update(['available' => 9129, 'reserved' => 0, 'total' => 9129]);
        $providerTotal?->balance?->update(['available' => 158238, 'reserved' => 0, 'total' => 158238]);
        $merchantParent?->balance?->update(['available' => 166129, 'reserved' => 0, 'total' => 166129]);

        $this->artisan('wallet:sync-parent-balances', ['merchantId' => $merchant->id])
            ->assertSuccessful();

        $this->assertSame('9129.0000', (string) $providerTotal?->fresh()->balance?->total);
        $this->assertSame('9129.0000', (string) $merchantParent?->fresh()->balance?->total);
        $this->assertSame('9129.0000', (string) $merchantParent?->fresh()->balance?->available);
    }

    public function test_disbursement_consume_recomputes_provider_and_parent_totals(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $disbursementWallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->with(['balance', 'parentWallet.balance', 'parentWallet.parentWallet.balance'])
            ->firstOrFail();

        $providerTotal = $disbursementWallet->parentWallet;
        $merchantParent = $providerTotal?->parentWallet;

        $this->assertNotNull($providerTotal);
        $this->assertNotNull($merchantParent);

        $disbursementWallet->balance->update(['available' => 1600, 'reserved' => 0, 'total' => 1600]);
        app(WalletLedgerService::class)->syncHierarchyBalances($merchant->id);

        $providerTotal->refresh()->load('balance');
        $merchantParent->refresh()->load('balance');
        $this->assertSame('1600.0000', (string) $providerTotal->balance->total);
        $this->assertSame('1600.0000', (string) $merchantParent->balance->total);

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-DISBURSE-ANCESTOR-001',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $disbursementWallet->provider_network_id,
            'request_id' => 'req-disburse-ancestor-001',
            'reference' => 'PAY-ANCESTOR-001',
            'operation' => PaymentOperation::B2cDisbursement,
            'status' => TransactionStatus::FundsReserved,
            'amount' => 1000,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
        ]);

        $ledger = app(WalletLedgerService::class);
        $ledger->reserveFunds($disbursementWallet->fresh(['balance']), $transaction, '1600.0000');

        $providerTotal->refresh()->load('balance');
        $merchantParent->refresh()->load('balance');
        $this->assertSame('0.0000', (string) $providerTotal->balance->available);
        $this->assertSame('1600.0000', (string) $providerTotal->balance->reserved);
        $this->assertSame('1600.0000', (string) $providerTotal->balance->total);

        $ledger->consumeFunds($transaction->fresh());

        $disbursementWallet->refresh()->load('balance');
        $providerTotal->refresh()->load('balance');
        $merchantParent->refresh()->load('balance');

        $this->assertSame('0.0000', (string) $disbursementWallet->balance->total);
        $this->assertSame('0.0000', (string) $providerTotal->balance->total);
        $this->assertSame('0.0000', (string) $merchantParent->balance->total);
        $this->assertSame('0.0000', (string) $merchantParent->balance->available);
        $this->assertSame('0.0000', (string) $merchantParent->balance->reserved);
    }
}
