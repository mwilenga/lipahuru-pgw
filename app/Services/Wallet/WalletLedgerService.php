<?php

namespace App\Services\Wallet;

use App\Enums\GatewayErrorCode;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\BalanceReservation;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletBalance;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;

class WalletLedgerService
{
    public function __construct(
        private readonly WalletRepositoryInterface $walletRepository,
    ) {}

    public function reserveFunds(Wallet $wallet, Transaction $transaction, string $amount): BalanceReservation
    {
        return DB::transaction(function () use ($wallet, $transaction, $amount): BalanceReservation {
            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($wallet->id);

            if ($lockedWallet?->balance === null) {
                throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet balance not found', httpStatus: 422);
            }

            $balance = $lockedWallet->balance;

            if (bccomp((string) $balance->available, $amount, 4) < 0) {
                throw new GatewayException(GatewayErrorCode::InsufficientBalance, httpStatus: 422);
            }

            $availableAfter = bcsub((string) $balance->available, $amount, 4);
            $reservedAfter = bcadd((string) $balance->reserved, $amount, 4);

            $balance->update([
                'available' => $availableAfter,
                'reserved' => $reservedAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $transaction->id,
                'entry_type' => 'RESERVE',
                'amount' => $amount,
                'currency' => $transaction->currency,
                'balance_after' => $availableAfter,
                'reference' => $transaction->transaction_id,
                'description' => 'Funds reserved for disbursement',
                'created_at' => now(),
            ]);

            return BalanceReservation::query()->create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'currency' => $transaction->currency,
                'status' => 'ACTIVE',
            ]);
        });
    }

    public function releaseFunds(Transaction $transaction): ?BalanceReservation
    {
        return DB::transaction(function () use ($transaction): ?BalanceReservation {
            $reservation = BalanceReservation::query()
                ->where('transaction_id', $transaction->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return null;
            }

            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($reservation->wallet_id);

            if ($lockedWallet?->balance === null) {
                return null;
            }

            $balance = $lockedWallet->balance;
            $amount = (string) $reservation->amount;

            $availableAfter = bcadd((string) $balance->available, $amount, 4);
            $reservedAfter = bcsub((string) $balance->reserved, $amount, 4);

            $balance->update([
                'available' => $availableAfter,
                'reserved' => $reservedAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $reservation->wallet_id,
                'transaction_id' => $transaction->id,
                'entry_type' => 'RELEASE',
                'amount' => $amount,
                'currency' => $reservation->currency,
                'balance_after' => $availableAfter,
                'reference' => $transaction->transaction_id,
                'description' => 'Reserved funds released',
                'created_at' => now(),
            ]);

            $reservation->update([
                'status' => 'RELEASED',
                'released_at' => now(),
            ]);

            return $reservation->refresh();
        });
    }

    public function consumeFunds(Transaction $transaction): ?BalanceReservation
    {
        return DB::transaction(function () use ($transaction): ?BalanceReservation {
            $reservation = BalanceReservation::query()
                ->where('transaction_id', $transaction->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return null;
            }

            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($reservation->wallet_id);

            if ($lockedWallet?->balance === null) {
                return null;
            }

            $balance = $lockedWallet->balance;
            $amount = (string) $reservation->amount;

            $reservedAfter = bcsub((string) $balance->reserved, $amount, 4);
            $totalAfter = bcsub((string) $balance->total, $amount, 4);

            $balance->update([
                'reserved' => $reservedAfter,
                'total' => $totalAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $reservation->wallet_id,
                'transaction_id' => $transaction->id,
                'entry_type' => 'DEBIT',
                'amount' => $amount,
                'currency' => $reservation->currency,
                'balance_after' => $totalAfter,
                'reference' => $transaction->transaction_id,
                'description' => 'Disbursement funds consumed',
                'created_at' => now(),
            ]);

            $reservation->update([
                'status' => 'CONSUMED',
                'consumed_at' => now(),
            ]);

            return $reservation->refresh();
        });
    }

    public function creditCollectionWallet(Wallet $wallet, Transaction $transaction, string $amount): void
    {
        DB::transaction(function () use ($wallet, $transaction, $amount): void {
            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($wallet->id);

            if ($lockedWallet?->balance === null) {
                throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet balance not found', httpStatus: 422);
            }

            $balance = $lockedWallet->balance;
            $availableAfter = bcadd((string) $balance->available, $amount, 4);
            $totalAfter = bcadd((string) $balance->total, $amount, 4);

            $balance->update([
                'available' => $availableAfter,
                'total' => $totalAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $transaction->id,
                'entry_type' => 'CREDIT',
                'amount' => $amount,
                'currency' => $transaction->currency,
                'balance_after' => $totalAfter,
                'reference' => $transaction->transaction_id,
                'description' => 'Collection credited to merchant wallet',
                'created_at' => now(),
            ]);

            if ($wallet->parent_wallet_id !== null) {
                $this->postMirrorCredit(
                    walletId: $wallet->parent_wallet_id,
                    amount: $amount,
                    currency: $transaction->currency,
                    reference: $transaction->transaction_id,
                    description: 'Collection mirror credit on parent wallet',
                    transactionId: $transaction->id,
                );
            }
        });
    }

    public function creditDisbursementFloat(
        Wallet $wallet,
        string $amount,
        string $currency,
        string $reference,
        string $description,
    ): void {
        if ($wallet->wallet_type !== WalletType::DisbursementLeaf) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'Float topups can only credit disbursement wallets',
                httpStatus: 422,
            );
        }

        DB::transaction(function () use ($wallet, $amount, $currency, $reference, $description): void {
            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($wallet->id);

            if ($lockedWallet?->balance === null) {
                throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet balance not found', httpStatus: 422);
            }

            $balance = $lockedWallet->balance;
            $availableAfter = bcadd((string) $balance->available, $amount, 4);
            $totalAfter = bcadd((string) $balance->total, $amount, 4);

            $balance->update([
                'available' => $availableAfter,
                'total' => $totalAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $wallet->id,
                'transaction_id' => null,
                'entry_type' => 'CREDIT',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $totalAfter,
                'reference' => $reference,
                'description' => $description,
                'created_at' => now(),
            ]);

            if ($wallet->parent_wallet_id !== null) {
                $this->postMirrorCredit(
                    walletId: $wallet->parent_wallet_id,
                    amount: $amount,
                    currency: $currency,
                    reference: $reference,
                    description: 'Float topup mirror credit on parent wallet',
                );
            }
        });
    }

    /**
     * Hold funds on the source wallet while a transfer awaits approval.
     */
    public function holdTransferFunds(
        Wallet $from,
        string $amount,
        string $currency,
        string $reference,
    ): void {
        DB::transaction(function () use ($from, $amount, $currency, $reference): void {
            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($from->id);

            if ($lockedWallet?->balance === null) {
                throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet balance not found', httpStatus: 422);
            }

            $balance = $lockedWallet->balance;

            if (bccomp((string) $balance->available, $amount, 4) < 0) {
                throw new GatewayException(GatewayErrorCode::InsufficientBalance, httpStatus: 422);
            }

            $availableAfter = bcsub((string) $balance->available, $amount, 4);
            $reservedAfter = bcadd((string) $balance->reserved, $amount, 4);

            $balance->update([
                'available' => $availableAfter,
                'reserved' => $reservedAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $lockedWallet->id,
                'transaction_id' => null,
                'entry_type' => 'TRANSFER_HOLD',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $availableAfter,
                'reference' => $reference,
                'description' => 'Funds held for wallet transfer',
                'created_at' => now(),
            ]);

            $this->recomputeAncestorBalances($lockedWallet);
        });
    }

    /**
     * Release a hold back to available funds (transfer rejected).
     */
    public function releaseTransferFunds(
        Wallet $from,
        string $amount,
        string $currency,
        string $reference,
    ): void {
        DB::transaction(function () use ($from, $amount, $currency, $reference): void {
            $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($from->id);

            if ($lockedWallet?->balance === null) {
                throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet balance not found', httpStatus: 422);
            }

            $balance = $lockedWallet->balance;
            $availableAfter = bcadd((string) $balance->available, $amount, 4);
            $reservedAfter = bcsub((string) $balance->reserved, $amount, 4);

            $balance->update([
                'available' => $availableAfter,
                'reserved' => $reservedAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $lockedWallet->id,
                'transaction_id' => null,
                'entry_type' => 'TRANSFER_RELEASE',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $availableAfter,
                'reference' => $reference,
                'description' => 'Wallet transfer hold released',
                'created_at' => now(),
            ]);

            $this->recomputeAncestorBalances($lockedWallet);
        });
    }

    /**
     * Move held funds from the source wallet to the destination wallet.
     */
    public function settleTransfer(
        Wallet $from,
        Wallet $to,
        string $amount,
        string $currency,
        string $reference,
    ): void {
        DB::transaction(function () use ($from, $to, $amount, $currency, $reference): void {
            // Lock in a stable order so concurrent transfers cannot deadlock.
            $ids = [$from->id, $to->id];
            sort($ids);

            $locked = [];

            foreach ($ids as $walletId) {
                $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($walletId);

                if ($lockedWallet?->balance === null) {
                    throw new GatewayException(GatewayErrorCode::GeneralError, 'Wallet balance not found', httpStatus: 422);
                }

                $locked[$walletId] = $lockedWallet;
            }

            $source = $locked[$from->id];
            $destination = $locked[$to->id];

            $sourceBalance = $source->balance;

            if (bccomp((string) $sourceBalance->reserved, $amount, 4) < 0) {
                throw new GatewayException(
                    GatewayErrorCode::InsufficientBalance,
                    'Held funds are no longer available for this transfer.',
                    httpStatus: 422,
                );
            }

            $sourceReservedAfter = bcsub((string) $sourceBalance->reserved, $amount, 4);
            $sourceTotalAfter = bcsub((string) $sourceBalance->total, $amount, 4);

            $sourceBalance->update([
                'reserved' => $sourceReservedAfter,
                'total' => $sourceTotalAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $source->id,
                'transaction_id' => null,
                'entry_type' => 'TRANSFER_OUT',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $sourceTotalAfter,
                'reference' => $reference,
                'description' => 'Wallet transfer debited',
                'created_at' => now(),
            ]);

            $destinationBalance = $destination->balance;
            $destinationAvailableAfter = bcadd((string) $destinationBalance->available, $amount, 4);
            $destinationTotalAfter = bcadd((string) $destinationBalance->total, $amount, 4);

            $destinationBalance->update([
                'available' => $destinationAvailableAfter,
                'total' => $destinationTotalAfter,
            ]);

            LedgerEntry::query()->create([
                'wallet_id' => $destination->id,
                'transaction_id' => null,
                'entry_type' => 'TRANSFER_IN',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $destinationTotalAfter,
                'reference' => $reference,
                'description' => 'Wallet transfer credited',
                'created_at' => now(),
            ]);

            $this->recomputeAncestorBalances($source);
            $this->recomputeAncestorBalances($destination);
        });
    }

    public function hasCollectionCredit(Transaction $transaction): bool
    {
        return LedgerEntry::query()
            ->where('transaction_id', $transaction->id)
            ->where('entry_type', 'CREDIT')
            ->exists();
    }

    public function syncParentWalletBalances(?int $merchantId = null): int
    {
        $query = Wallet::query()
            ->where('wallet_type', WalletType::MerchantParent)
            ->with(['balance', 'childWallets.balance']);

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        $updated = 0;

        foreach ($query->get() as $parentWallet) {
            if ($parentWallet->balance === null) {
                continue;
            }

            $available = '0.0000';
            $reserved = '0.0000';
            $total = '0.0000';

            foreach ($parentWallet->childWallets as $childWallet) {
                if ($childWallet->balance === null) {
                    continue;
                }

                $available = bcadd($available, (string) $childWallet->balance->available, 4);
                $reserved = bcadd($reserved, (string) $childWallet->balance->reserved, 4);
                $total = bcadd($total, (string) $childWallet->balance->total, 4);
            }

            $parentWallet->balance->update([
                'available' => $available,
                'reserved' => $reserved,
                'total' => $total,
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * Rebuild each ancestor of a leaf wallet from the sum of its children.
     * syncParentWalletBalances() only rebuilds MERCHANT_PARENT, so provider
     * totals need this when balances move directly between leaves.
     */
    private function recomputeAncestorBalances(Wallet $wallet): void
    {
        $parentId = $wallet->parent_wallet_id;

        while ($parentId !== null) {
            $parent = $this->walletRepository->findWithBalanceForUpdate($parentId);

            if ($parent === null) {
                return;
            }

            if ($parent->balance !== null) {
                $available = '0.0000';
                $reserved = '0.0000';
                $total = '0.0000';

                $children = Wallet::query()
                    ->where('parent_wallet_id', $parent->id)
                    ->with('balance')
                    ->get();

                foreach ($children as $child) {
                    if ($child->balance === null) {
                        continue;
                    }

                    $available = bcadd($available, (string) $child->balance->available, 4);
                    $reserved = bcadd($reserved, (string) $child->balance->reserved, 4);
                    $total = bcadd($total, (string) $child->balance->total, 4);
                }

                $parent->balance->update([
                    'available' => $available,
                    'reserved' => $reserved,
                    'total' => $total,
                ]);
            }

            $parentId = $parent->parent_wallet_id;
        }
    }

    private function postMirrorCredit(
        int $walletId,
        string $amount,
        string $currency,
        string $reference,
        string $description,
        ?int $transactionId = null,
    ): void {
        $lockedWallet = $this->walletRepository->findWithBalanceForUpdate($walletId);

        if ($lockedWallet?->balance === null) {
            return;
        }

        $balance = $lockedWallet->balance;
        $availableAfter = bcadd((string) $balance->available, $amount, 4);
        $totalAfter = bcadd((string) $balance->total, $amount, 4);

        $balance->update([
            'available' => $availableAfter,
            'total' => $totalAfter,
        ]);

        LedgerEntry::query()->create([
            'wallet_id' => $walletId,
            'transaction_id' => $transactionId,
            'entry_type' => 'CREDIT',
            'amount' => $amount,
            'currency' => $currency,
            'balance_after' => $totalAfter,
            'reference' => $reference,
            'description' => $description,
            'created_at' => now(),
        ]);

        if ($lockedWallet->parent_wallet_id !== null) {
            $this->postMirrorCredit(
                walletId: $lockedWallet->parent_wallet_id,
                amount: $amount,
                currency: $currency,
                reference: $reference,
                description: $description,
                transactionId: $transactionId,
            );
        }
    }
}
