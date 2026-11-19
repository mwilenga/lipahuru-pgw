<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Merchant\CommissionFeeCalculator;
use App\Services\Provider\ProviderFeeCalculator;
use Illuminate\Console\Command;

class BackfillTransactionFeesCommand extends Command
{
    protected $signature = 'transactions:backfill-fees
        {--dry-run : Show how many rows would be updated without writing}
        {--chunk=200 : Chunk size}';

    protected $description = 'Backfill merchant/provider fees on SUCCESS txs (C2B: 0.75% before 2026-10-01 else settings; B2C: always FIXED 500)';

    public function handle(
        CommissionFeeCalculator $commissionFeeCalculator,
        ProviderFeeCalculator $providerFeeCalculator,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $updated = 0;
        $preCutoff = 0;
        $postCutoff = 0;

        Transaction::query()
            ->where('status', TransactionStatus::Success)
            ->with('merchant')
            ->orderBy('id')
            ->chunkById($chunk, function ($transactions) use (
                $commissionFeeCalculator,
                $providerFeeCalculator,
                $dryRun,
                &$updated,
                &$preCutoff,
                &$postCutoff,
            ): void {
                foreach ($transactions as $transaction) {
                    /** @var Transaction $transaction */
                    $changes = [];

                    if ($transaction->merchant_fee_amount === null) {
                        $changes['merchant_fee_amount'] = $commissionFeeCalculator->forTransaction($transaction)['fee'];
                    }

                    if ($transaction->operation !== null) {
                        $provider = $providerFeeCalculator->backfillForTransaction($transaction);
                        $asOf = $transaction->finalized_at ?? $transaction->created_at;
                        if ($transaction->operation?->value === 'C2B_USSD_PUSH'
                            && $providerFeeCalculator->isBeforeLegacyCutoff($asOf)) {
                            $preCutoff++;
                        } elseif ($transaction->operation?->value === 'C2B_USSD_PUSH') {
                            $postCutoff++;
                        }

                        $changes['provider_fee_amount'] = $provider['fee'];
                        $changes['provider_fee_rate_id'] = $provider['rate']?->id;
                    }

                    if ($changes === []) {
                        continue;
                    }

                    $updated++;

                    if (! $dryRun) {
                        $transaction->update($changes);
                    }
                }
            });

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$updated} successful transaction(s).");
        $this->line('  C2B before '.ProviderFeeCalculator::LEGACY_CUTOFF.' (0.75%): '.$preCutoff);
        $this->line('  C2B on/after cutoff (settings): '.$postCutoff);
        $this->line('  B2C always FIXED '.ProviderFeeCalculator::B2C_FIXED_FEE);

        return self::SUCCESS;
    }
}
