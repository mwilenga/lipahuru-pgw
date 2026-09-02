<?php

namespace Tests\Feature;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletBalance;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\CollapseMerchantBalancesService;
use Tests\GatewayTestCase;

class WalletBalanceTest extends GatewayTestCase
{
    public function test_collection_credit_updates_merchant_balance(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::MerchantBalance)
            ->with('balance')
            ->firstOrFail();

        $networkId = $merchant->providerProfiles()->firstOrFail()->provider_network_id;

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-WALLET-TEST-001',
            'merchant_id' => $merchant->id,
            'provider_network_id' => $networkId,
            'request_id' => 'req-wallet-001',
            'reference' => 'INV-WALLET-001',
            'operation' => PaymentOperation::C2bPush,
            'status' => TransactionStatus::Acknowledged,
            'amount' => 100,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
        ]);

        app(PaymentService::class)->finalizeSuccess($transaction);

        $wallet->refresh()->load('balance');

        $this->assertSame('100.0000', (string) $wallet->balance->available);
        $this->assertSame('100.0000', (string) $wallet->balance->total);
    }

    public function test_collapse_sums_leaf_balances_into_merchant_balance(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        // Simulate a pre-collapse hierarchy by creating inactive-style leaves.
        $merchant->wallets()->where('wallet_type', WalletType::MerchantBalance)->delete();

        $collection = Wallet::query()->create([
            'merchant_id' => $merchant->id,
            'wallet_type' => WalletType::CollectionLeaf,
            'currency' => 'TZS',
            'name' => 'Legacy collection',
            'is_active' => true,
        ]);
        WalletBalance::query()->create([
            'wallet_id' => $collection->id,
            'available' => '3000.0000',
            'reserved' => '500.0000',
            'total' => '3500.0000',
        ]);

        $disbursement = Wallet::query()->create([
            'merchant_id' => $merchant->id,
            'wallet_type' => WalletType::DisbursementLeaf,
            'currency' => 'TZS',
            'name' => 'Legacy disbursement',
            'is_active' => true,
        ]);
        WalletBalance::query()->create([
            'wallet_id' => $disbursement->id,
            'available' => '2000.0000',
            'reserved' => '0.0000',
            'total' => '2000.0000',
        ]);

        $result = app(CollapseMerchantBalancesService::class)->collapse($merchant->id);

        $this->assertSame(1, $result['created']);

        $balance = $merchant->wallets()
            ->where('wallet_type', WalletType::MerchantBalance)
            ->where('is_active', true)
            ->with('balance')
            ->firstOrFail();

        $this->assertSame('5000.0000', (string) $balance->balance->available);
        $this->assertSame('500.0000', (string) $balance->balance->reserved);
        $this->assertSame('5500.0000', (string) $balance->balance->total);

        $this->assertSame(
            0,
            $merchant->wallets()
                ->whereIn('wallet_type', [WalletType::CollectionLeaf, WalletType::DisbursementLeaf])
                ->where('is_active', true)
                ->count(),
        );
    }
}
