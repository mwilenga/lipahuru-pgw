<?php

namespace App\Console\Commands;

use App\Services\Wallet\WalletLedgerService;
use Illuminate\Console\Command;

class SyncParentWalletBalancesCommand extends Command
{
    protected $signature = 'wallet:sync-parent-balances {merchantId? : Optional merchant id to scope the sync}';

    protected $description = 'Reconcile merchant parent wallet balances from provider total child wallets';

    public function handle(WalletLedgerService $walletLedgerService): int
    {
        $merchantId = $this->argument('merchantId');
        $scopedMerchantId = $merchantId !== null ? (int) $merchantId : null;

        $updated = $walletLedgerService->syncParentWalletBalances($scopedMerchantId);

        $this->info("Synced {$updated} parent wallet(s).");

        return self::SUCCESS;
    }
}
