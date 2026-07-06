<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\MerchantProviderProfile;
use App\Models\ProviderNetwork;
use Illuminate\Console\Command;

class EnableMerchantProvidersCommand extends Command
{
    protected $signature = 'gateway:enable-merchant-providers
                            {merchant? : Merchant ID or email — omit to fix all merchants}';

    protected $description = 'Create or enable provider profiles (VODACOM, YAS, etc.) for merchants';

    public function handle(): int
    {
        $merchantArg = $this->argument('merchant');

        $merchants = $merchantArg
            ? Merchant::query()
                ->where('id', $merchantArg)
                ->orWhere('email', $merchantArg)
                ->get()
            : Merchant::query()->get();

        if ($merchants->isEmpty()) {
            $this->error('No merchant found.');

            return self::FAILURE;
        }

        $networks = ProviderNetwork::query()->where('is_active', true)->get();

        foreach ($merchants as $merchant) {
            foreach ($networks as $network) {
                MerchantProviderProfile::query()->updateOrCreate(
                    [
                        'merchant_id' => $merchant->id,
                        'provider_network_id' => $network->id,
                    ],
                    [
                        'is_enabled' => true,
                        'min_amount' => 100,
                        'max_amount' => 10000000,
                    ],
                );
            }

            $this->line("Enabled providers for merchant #{$merchant->id} ({$merchant->email})");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
