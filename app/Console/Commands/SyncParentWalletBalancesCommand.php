<?php

namespace App\Console\Commands;

use App\Services\Wallet\WalletLedgerService;
use Illuminate\Console\Command;

class SyncParentWalletBalancesCommand extends Command
{
    protected $signature = 'wallet:sync-parent-balances {merchantId? : Optional merchant id to scope the sync}';

    protected $description = 'Rebuild PROVIDER_TOTAL balances from leaves, then MERCHANT_PARENT from provider totals';

    public function handle(WalletLedgerService $walletLedgerService): int
    {
        $merchantId = $this->argument('merchantId');
        $scopedMerchantId = $merchantId !== null ? (int) $merchantId : null;

        $updated = $walletLedgerService->syncHierarchyBalances($scopedMerchantId);

        $this->info("Synced {$updated} provider-total and parent wallet(s).");

        return self::SUCCESS;
    }
}
