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
}
