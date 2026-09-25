<?php

namespace App\Services\Wallet;

use App\Enums\GatewayErrorCode;
use App\Enums\WalletTransferSource;
use App\Enums\WalletTransferStatus;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use App\Services\Sms\AdminApprovalSmsNotifier;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletTransferService
{
    private const MINIMUM_AMOUNT = '100.0000';

    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
        private readonly AdminApprovalSmsNotifier $adminApprovalSmsNotifier,
    ) {}

    public function requestByMerchant(
        Merchant $merchant,
        MerchantUser $user,
        int $fromWalletId,
        int $toWalletId,
        string $amount,
        ?string $reference = null,
        ?string $notes = null,
    ): WalletTransfer {
        [$from, $to, $normalizedAmount] = $this->resolve($merchant, $fromWalletId, $toWalletId, $amount);

        $transfer = DB::transaction(function () use ($merchant, $user, $from, $to, $normalizedAmount, $reference, $notes): WalletTransfer {
            $transfer = WalletTransfer::query()->create([
                'transfer_id' => $this->generateTransferId(),
                'merchant_id' => $merchant->id,
                'from_wallet_id' => $from->id,
                'to_wallet_id' => $to->id,
                'amount' => $normalizedAmount,
                'currency' => $from->currency ?? ($merchant->default_currency ?? 'TZS'),
                'status' => WalletTransferStatus::PendingApproval,
                'source' => WalletTransferSource::Merchant,
                'reference' => $reference,
                'notes' => $notes,
                'requested_by_type' => MerchantUser::class,
                'requested_by_id' => $user->id,
            ]);

            $this->walletLedgerService->holdTransferFunds(
                from: $from,
                amount: $normalizedAmount,
                currency: $transfer->currency,
                reference: $transfer->transfer_id,
            );

            return $transfer->fresh(['merchant', 'fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer']);
        });

        try {
            $this->adminApprovalSmsNotifier->notifyWalletTransfer($transfer);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Wallet transfer admin SMS failed.', [
                'transfer_id' => $transfer->transfer_id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $transfer;
    }

    public function createDirectByAdmin(
        Merchant $merchant,
        AdminUser $admin,
        int $fromWalletId,
        int $toWalletId,
        string $amount,
        ?string $reference = null,
        ?string $notes = null,
    ): WalletTransfer {
        [$from, $to, $normalizedAmount] = $this->resolve($merchant, $fromWalletId, $toWalletId, $amount);

        return DB::transaction(function () use ($merchant, $admin, $from, $to, $normalizedAmount, $reference, $notes): WalletTransfer {
            $transfer = WalletTransfer::query()->create([
                'transfer_id' => $this->generateTransferId(),
                'merchant_id' => $merchant->id,
                'from_wallet_id' => $from->id,
                'to_wallet_id' => $to->id,
                'amount' => $normalizedAmount,
                'currency' => $from->currency ?? ($merchant->default_currency ?? 'TZS'),
                'status' => WalletTransferStatus::Approved,
                'source' => WalletTransferSource::Admin,
                'reference' => $reference,
                'notes' => $notes,
                'requested_by_type' => AdminUser::class,
                'requested_by_id' => $admin->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $this->walletLedgerService->holdTransferFunds(
                from: $from,
                amount: $normalizedAmount,
                currency: $transfer->currency,
                reference: $transfer->transfer_id,
            );

            $this->walletLedgerService->settleTransfer(
                from: $from,
                to: $to,
                amount: $normalizedAmount,
                currency: $transfer->currency,
                reference: $transfer->transfer_id,
            );

            return $transfer->fresh(['merchant', 'fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer']);
        });
    }

    public function approve(WalletTransfer $transfer, AdminUser $admin): WalletTransfer
    {
        return DB::transaction(function () use ($transfer, $admin): WalletTransfer {
            $locked = $this->lockPending($transfer, 'approved');
            $locked->load(['fromWallet', 'toWallet']);

            $this->walletLedgerService->settleTransfer(
                from: $locked->fromWallet,
                to: $locked->toWallet,
                amount: (string) $locked->amount,
                currency: $locked->currency,
                reference: $locked->transfer_id,
            );

            $locked->update([
                'status' => WalletTransferStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh(['merchant', 'fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer']);
        });
    }

    public function reject(WalletTransfer $transfer, AdminUser $admin, ?string $reason = null): WalletTransfer
    {
        return DB::transaction(function () use ($transfer, $admin, $reason): WalletTransfer {
            $locked = $this->lockPending($transfer, 'rejected');
            $locked->load('fromWallet');

            $this->walletLedgerService->releaseTransferFunds(
                from: $locked->fromWallet,
                amount: (string) $locked->amount,
                currency: $locked->currency,
                reference: $locked->transfer_id,
            );

            $locked->update([
                'status' => WalletTransferStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh(['merchant', 'fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer']);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForMerchant(Merchant $merchant, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->where('merchant_id', $merchant->id)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForAdmin(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->when(
                isset($filters['merchantId']) && $filters['merchantId'] !== '',
                fn ($query) => $query->where('merchant_id', (int) $filters['merchantId']),
            )
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Leaf wallets a merchant can move funds between.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Wallet>
     */
    public function transferableWallets(Merchant $merchant)
    {
        return Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('wallet_type', [WalletType::CollectionLeaf, WalletType::DisbursementLeaf])
            ->where('is_active', true)
            ->with(['balance', 'providerNetwork'])
            ->orderBy('wallet_type')
            ->get();
    }

    public function findForMerchantOrFail(int $id, Merchant $merchant): WalletTransfer
    {
        return WalletTransfer::query()
            ->where('merchant_id', $merchant->id)
            ->with(['fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer'])
            ->findOrFail($id);
    }

    public function findOrFail(int $id): WalletTransfer
    {
        return WalletTransfer::query()
            ->with(['merchant', 'fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer'])
            ->findOrFail($id);
    }

    private function lockPending(WalletTransfer $transfer, string $action): WalletTransfer
    {
        $locked = WalletTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== WalletTransferStatus::PendingApproval) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                "Only pending transfers can be {$action}.",
                httpStatus: 422,
            );
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters)
    {
        $tz = (string) config('payment-gateway.filter_timezone', 'Africa/Dar_es_Salaam');

        return WalletTransfer::query()
            ->with(['merchant', 'fromWallet.providerNetwork', 'toWallet.providerNetwork', 'reviewer'])
            ->when(
                isset($filters['status']) && $filters['status'] !== '',
                fn ($query) => $query->where('status', strtoupper((string) $filters['status'])),
            )
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $search = (string) $filters['search'];

                $query->where(function ($inner) use ($search): void {
                    $inner->where('transfer_id', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['from']) && $filters['from'] !== '', function ($query) use ($filters, $tz): void {
                $query->where('created_at', '>=', Carbon::parse((string) $filters['from'], $tz)->startOfDay()->utc());
            })
            ->when(isset($filters['to']) && $filters['to'] !== '', function ($query) use ($filters, $tz): void {
                $query->where('created_at', '<=', Carbon::parse((string) $filters['to'], $tz)->endOfDay()->utc());
            });
    }

    /**
     * @return array{0: Wallet, 1: Wallet, 2: string}
     */
    private function resolve(Merchant $merchant, int $fromWalletId, int $toWalletId, string $amount): array
    {
        $normalizedAmount = number_format((float) $amount, 4, '.', '');

        if (bccomp($normalizedAmount, self::MINIMUM_AMOUNT, 4) < 0) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'Transfer amount must be at least 100.',
                httpStatus: 422,
            );
        }

        if ($fromWalletId === $toWalletId) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'Source and destination wallets must be different.',
                httpStatus: 422,
            );
        }

        $from = $this->resolveWallet($merchant, $fromWalletId, 'Source');
        $to = $this->resolveWallet($merchant, $toWalletId, 'Destination');

        if (($from->currency ?? 'TZS') !== ($to->currency ?? 'TZS')) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'Wallet currencies must match.',
                httpStatus: 422,
            );
        }

        $available = (string) ($from->balance?->available ?? '0.0000');

        if (bccomp($available, $normalizedAmount, 4) < 0) {
            throw new GatewayException(GatewayErrorCode::InsufficientBalance, httpStatus: 422);
        }

        return [$from, $to, $normalizedAmount];
    }

    private function resolveWallet(Merchant $merchant, int $walletId, string $label): Wallet
    {
        $wallet = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->with('balance')
            ->find($walletId);

        if ($wallet === null) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                "{$label} wallet not found for this merchant.",
                httpStatus: 422,
            );
        }

        if (! $wallet->is_active) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                "{$label} wallet is not active.",
                httpStatus: 422,
            );
        }

        if (! in_array($wallet->wallet_type, [WalletType::CollectionLeaf, WalletType::DisbursementLeaf], true)) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                "{$label} wallet must be a collection or disbursement wallet.",
                httpStatus: 422,
            );
        }

        return $wallet;
    }

    private function generateTransferId(): string
    {
        return 'TRF-'.strtoupper(Str::random(16));
    }
}
