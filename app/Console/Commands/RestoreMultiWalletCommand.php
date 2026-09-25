<?php

namespace App\Console\Commands;

use App\Services\Wallet\RestoreMultiWalletService;
use Illuminate\Console\Command;

class RestoreMultiWalletCommand extends Command
{
    protected $signature = 'wallets:restore-multi-wallet
                            {--merchant= : Limit to a single merchant id}
                            {--dry-run : Preview without writing}
                            {--force : Restore even when leaf totals diverge from MERCHANT_BALANCE}';

    protected $description = 'Reactivate multi-wallet hierarchy and deactivate MERCHANT_BALANCE for collapsed merchants';

    public function handle(RestoreMultiWalletService $service): int
    {
        $merchantOption = $this->option('merchant');
        $merchantId = $merchantOption !== null && $merchantOption !== ''
            ? (int) $merchantOption
            : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $result = $service->restore(
            merchantId: $merchantId,
            dryRun: $dryRun,
            force: $force,
        );

        if ($result['merchants'] === 0) {
            $this->info('No collapsed merchants found.');

            return self::SUCCESS;
        }

        $this->table(
            [
                'Merchant',
                'Name',
                'Inactive leaves',
                'Leaf total',
                'MB total',
                'Mismatch',
                'Action',
            ],
            collect($result['details'])->map(static fn (array $row): array => [
                $row['merchantId'],
                $row['merchantName'],
                $row['inactiveLeaves'],
                $row['leafTotal'],
                $row['merchantBalanceTotal'],
                $row['mismatch'] ? 'yes' : 'no',
                $row['action'],
            ])->all(),
        );

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%sCollapsed candidates: %d — restored/would restore: %d — skipped (mismatch): %d',
            $prefix,
            $result['merchants'],
            $result['restored'],
            $result['skipped'],
        ));

        if ($result['skipped'] > 0 && ! $force) {
            $this->warn('Merchants with balance mismatch were skipped. Re-run with --force after reviewing, or reconcile balances manually.');
        }

        return self::SUCCESS;
    }
}
