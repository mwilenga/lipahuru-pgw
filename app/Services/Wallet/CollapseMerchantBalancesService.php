<?php

namespace App\Services\Wallet;

use App\Enums\WalletType;
use App\Models\Merchant;
use App\Models\Wallet;
use App\Models\WalletBalance;
use Illuminate\Support\Facades\DB;

class CollapseMerchantBalancesService
{
    /**
     * Sum leaf balances into one MERCHANT_BALANCE wallet and deactivate the old hierarchy.
     *
     * @return array{merchants: int, created: int, updated: int}
     */
    public function collapse(?int $merchantId = null): array
    {
        $created = 0;
        $updated = 0;

        $query = Merchant::query()->orderBy('id');

        if ($merchantId !== null) {
            $query->whereKey($merchantId);
        }

        $merchants = $query->get();

        foreach ($merchants as $merchant) {
            $result = $this->collapseMerchant($merchant);

            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        return [
            'merchants' => $merchants->count(),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    private function collapseMerchant(Merchant $merchant): string
    {
        return DB::transaction(function () use ($merchant): string {
            $existing = Wallet::query()
                ->where('merchant_id', $merchant->id)
                ->where('wallet_type', WalletType::MerchantBalance)
                ->lockForUpdate()
                ->first();

            $leaves = Wallet::query()
                ->where('merchant_id', $merchant->id)
                ->whereIn('wallet_type', [WalletType::CollectionLeaf, WalletType::DisbursementLeaf])
                ->with('balance')
                ->lockForUpdate()
                ->get();

            $available = '0.0000';
            $reserved = '0.0000';
            $total = '0.0000';

            foreach ($leaves as $leaf) {
                if ($leaf->balance === null) {
                    continue;
                }

                $available = bcadd($available, (string) $leaf->balance->available, 4);
                $reserved = bcadd($reserved, (string) $leaf->balance->reserved, 4);
                $total = bcadd($total, (string) $leaf->balance->total, 4);
            }

            // Merchants onboarded after the single-balance change already have MERCHANT_BALANCE
            // and no leaves — leave their balances alone.
            if ($existing !== null && $leaves->isEmpty()) {
                return 'skipped';
            }

            if ($existing === null) {
                $wallet = Wallet::query()->create([
                    'merchant_id' => $merchant->id,
                    'wallet_type' => WalletType::MerchantBalance,
                    'currency' => $merchant->default_currency ?? 'TZS',
                    'name' => "{$merchant->name} Balance",
                    'is_active' => true,
                ]);

                WalletBalance::query()->create([
                    'wallet_id' => $wallet->id,
                    'available' => $available,
                    'reserved' => $reserved,
                    'total' => $total,
                ]);

                $this->deactivateLegacyWallets($merchant->id);
                $action = 'created';
            } else {
                if ($existing->balance === null) {
                    WalletBalance::query()->create([
                        'wallet_id' => $existing->id,
                        'available' => $available,
                        'reserved' => $reserved,
                        'total' => $total,
                    ]);
                } else {
                    $existing->balance->update([
                        'available' => $available,
                        'reserved' => $reserved,
                        'total' => $total,
                    ]);
                }

                $existing->update(['is_active' => true]);
                $this->deactivateLegacyWallets($merchant->id);
                $action = 'updated';
            }

            return $action;
        });
    }

    private function deactivateLegacyWallets(int $merchantId): void
    {
        Wallet::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('wallet_type', [
                WalletType::MerchantParent,
                WalletType::ProviderTotal,
                WalletType::CollectionLeaf,
                WalletType::DisbursementLeaf,
            ])
            ->update(['is_active' => false]);
    }
}
