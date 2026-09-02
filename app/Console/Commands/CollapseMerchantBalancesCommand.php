<?php

namespace App\Console\Commands;

use App\Services\Wallet\CollapseMerchantBalancesService;
use Illuminate\Console\Command;

class CollapseMerchantBalancesCommand extends Command
{
    protected $signature = 'wallets:collapse-to-single-balance {--merchant= : Limit to a single merchant id}';

    protected $description = 'Sum leaf wallet balances into one MERCHANT_BALANCE wallet and deactivate the old hierarchy';

    public function handle(CollapseMerchantBalancesService $service): int
    {
        $merchantId = $this->option('merchant');

        $result = $service->collapse(
            $merchantId !== null && $merchantId !== '' ? (int) $merchantId : null,
        );

        $this->info(sprintf(
            'Collapsed %d merchant(s): %d created, %d updated.',
            $result['merchants'],
            $result['created'],
            $result['updated'],
        ));

        return self::SUCCESS;
    }
}
