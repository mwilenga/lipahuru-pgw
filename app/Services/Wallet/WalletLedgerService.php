<?php

namespace App\Services\Wallet;

use App\Enums\GatewayErrorCode;
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
                $this->postMirrorEntry(
                    walletId: $wallet->parent_wallet_id,
                    transaction: $transaction,
                    amount: $amount,
                    description: 'Collection mirror credit on parent wallet',
                );
            }
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
            ->where('wallet_type', \App\Enums\WalletType::MerchantParent)
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

    private function postMirrorEntry(int $walletId, Transaction $transaction, string $amount, string $description): void
    {
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
            'transaction_id' => $transaction->id,
            'entry_type' => 'CREDIT',
            'amount' => $amount,
            'currency' => $transaction->currency,
            'balance_after' => $totalAfter,
            'reference' => $transaction->transaction_id,
            'description' => $description,
            'created_at' => now(),
        ]);

        if ($lockedWallet->parent_wallet_id !== null) {
            $this->postMirrorEntry(
                walletId: $lockedWallet->parent_wallet_id,
                transaction: $transaction,
                amount: $amount,
                description: 'Collection mirror credit on parent wallet',
            );
        }
    }
}
