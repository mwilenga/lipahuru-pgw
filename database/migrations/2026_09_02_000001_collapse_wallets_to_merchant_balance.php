<?php

use App\Services\Wallet\CollapseMerchantBalancesService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CollapseMerchantBalancesService::class)->collapse();
    }

    public function down(): void
    {
        // Irreversible: historical leaf wallets remain inactive; balances stay on MERCHANT_BALANCE.
    }
};
