<?php

namespace App\Services\Wallet;

use App\Enums\WalletType;
use App\Models\Merchant;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RestoreMultiWalletService
{
    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    /**
     * Merchants that still have a MERCHANT_BALANCE wallet and inactive leaf wallets.
     *
     * @return Collection<int, Merchant>
     */
    public function collapsedMerchants(?int $merchantId = null): Collection
    {
        $query = Merchant::query()
            ->whereHas('wallets', function ($q): void {
                $q->where('wallet_type', WalletType::MerchantBalance);
            })
            ->whereHas('wallets', function ($q): void {
                $q->whereIn('wallet_type', [
                    WalletType::CollectionLeaf,
                    WalletType::DisbursementLeaf,
                ])->where('is_active', false);
            })
            ->orderBy('id');

        if ($merchantId !== null) {
            $query->whereKey($merchantId);
        }

        return $query->get();
    }

    /**
     * @return array{
     *   merchants: int,
     *   restored: int,
     *   skipped: int,
     *   dryRun: bool,
     *   details: list<array<string, mixed>>
     * }
     */
    public function restore(?int $merchantId = null, bool $dryRun = false, bool $force = false): array
    {
        $merchants = $this->collapsedMerchants($merchantId);
        $details = [];
        $restored = 0;
        $skipped = 0;

        foreach ($merchants as $merchant) {
            $detail = $this->inspectMerchant($merchant);

            if ($detail['mismatch'] && ! $force) {
                $detail['action'] = 'skipped_mismatch';
                $details[] = $detail;
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $detail['action'] = $detail['mismatch'] ? 'would_force_restore' : 'would_restore';
                $details[] = $detail;
                $restored++;
                continue;
            }

            $this->restoreMerchant($merchant);
            $this->walletLedgerService->syncHierarchyBalances($merchant->id);

            $detail['action'] = $detail['mismatch'] ? 'force_restored' : 'restored';
            $details[] = $detail;
            $restored++;
        }

        return [
            'merchants' => $merchants->count(),
            'restored' => $restored,
            'skipped' => $skipped,
            'dryRun' => $dryRun,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectMerchant(Merchant $merchant): array
    {
        $merchantBalance = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->where('wallet_type', WalletType::MerchantBalance)
            ->with('balance')
            ->first();

        $leaves = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('wallet_type', [
                WalletType::CollectionLeaf,
                WalletType::DisbursementLeaf,
            ])
            ->with('balance')
            ->get();

        $leafTotal = '0.0000';
        $leafAvailable = '0.0000';
        $leafReserved = '0.0000';

        foreach ($leaves as $leaf) {
            if ($leaf->balance === null) {
                continue;
            }

            $leafTotal = bcadd($leafTotal, (string) $leaf->balance->total, 4);
            $leafAvailable = bcadd($leafAvailable, (string) $leaf->balance->available, 4);
            $leafReserved = bcadd($leafReserved, (string) $leaf->balance->reserved, 4);
        }

        $mbTotal = (string) ($merchantBalance?->balance?->total ?? '0.0000');
        $mismatch = bccomp($leafTotal, $mbTotal, 4) !== 0;

        return [
            'merchantId' => $merchant->id,
            'merchantName' => $merchant->name,
            'inactiveLeaves' => $leaves->where('is_active', false)->count(),
            'leafTotal' => $leafTotal,
            'leafAvailable' => $leafAvailable,
            'leafReserved' => $leafReserved,
            'merchantBalanceTotal' => $mbTotal,
            'mismatch' => $mismatch,
        ];
    }

    private function restoreMerchant(Merchant $merchant): void
    {
        DB::transaction(function () use ($merchant): void {
            Wallet::query()
                ->where('merchant_id', $merchant->id)
                ->whereIn('wallet_type', [
                    WalletType::MerchantParent,
                    WalletType::ProviderTotal,
                    WalletType::CollectionLeaf,
                    WalletType::DisbursementLeaf,
                ])
                ->update(['is_active' => true]);

            Wallet::query()
                ->where('merchant_id', $merchant->id)
                ->where('wallet_type', WalletType::MerchantBalance)
                ->update(['is_active' => false]);
        });
    }
}
