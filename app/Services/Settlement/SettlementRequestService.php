<?php

namespace App\Services\Settlement;

use App\Enums\GatewayErrorCode;
use App\Enums\PaymentOperation;
use App\Enums\SettlementRequestStatus;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\MerchantUser;
use App\Models\SettlementRequest;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Merchant\CommissionFeeCalculator;
use App\Services\Sms\AdminApprovalSmsNotifier;
use App\Services\Wallet\WalletLedgerService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SettlementRequestService
{
    private const MINIMUM_AMOUNT = '100.0000';

    private const RELATIONS = ['merchant', 'wallet.providerNetwork', 'requester', 'reviewer'];

    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly AdminApprovalSmsNotifier $adminApprovalSmsNotifier,
        private readonly CommissionFeeCalculator $commissionFeeCalculator,
    ) {}

    /**
     * Collection wallets with their balance after outstanding collection commission.
     *
     * @return list<array<string, mixed>>
     */
    public function settleableWallets(Merchant $merchant): array
    {
        $commission = $this->collectionCommission($merchant);

        return $this->collectionWallets($merchant)
            ->map(function (Wallet $wallet) use ($commission): array {
                $available = (string) ($wallet->balance?->available ?? '0.0000');
                $outstanding = $this->outstandingCommission($wallet, $commission);

                return [
                    'walletId' => $wallet->id,
                    'name' => $wallet->name,
                    'providerCode' => $wallet->providerNetwork?->code?->value,
                    'currency' => $wallet->currency,
                    'available' => $available,
                    'commissionOutstanding' => $outstanding,
                    'settleable' => $this->subtractFloorZero($available, $outstanding),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{type: string|null, value: string}
     */
    public function commissionSummary(Merchant $merchant): array
    {
        $commission = $this->collectionCommission($merchant);

        return [
            'type' => $commission?->commission_type?->value,
            'value' => number_format((float) ($commission?->value ?? 0), 4, '.', ''),
        ];
    }

    public function requestByMerchant(
        Merchant $merchant,
        MerchantUser $user,
        int $walletId,
        string $amount,
        ?string $memo = null,
    ): SettlementRequest {
        if (! $merchant->hasSettlementBankAccount()) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'No settlement bank account is set on your business profile. Contact LipaHuru support to add one.',
                httpStatus: 422,
            );
        }

        $normalizedAmount = number_format((float) $amount, 4, '.', '');

        if (bccomp($normalizedAmount, self::MINIMUM_AMOUNT, 4) < 0) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'Settlement amount must be at least 100.',
                httpStatus: 422,
            );
        }

        $wallet = $this->resolveCollectionWallet($merchant, $walletId);
        $commission = $this->collectionCommission($merchant);

        $request = DB::transaction(function () use ($merchant, $user, $wallet, $commission, $normalizedAmount, $memo): SettlementRequest {
            // Lock first so concurrent requests cannot both claim the same outstanding commission.
            $locked = $this->walletRepository->findWithBalanceForUpdate($wallet->id);
            $available = (string) ($locked?->balance?->available ?? '0.0000');
            $outstanding = $this->outstandingCommission($wallet, $commission);
            $totalDebit = bcadd($normalizedAmount, $outstanding, 4);

            if (bccomp($totalDebit, $available, 4) > 0) {
                throw new GatewayException(
                    GatewayErrorCode::InsufficientBalance,
                    'Amount exceeds the settleable balance on this wallet after charges.',
                    httpStatus: 422,
                );
            }

            $settlement = SettlementRequest::query()->create([
                'request_id' => $this->generateRequestId(),
                'merchant_id' => $merchant->id,
                'wallet_id' => $wallet->id,
                'amount' => $normalizedAmount,
                'commission_amount' => $outstanding,
                'total_debit' => $totalDebit,
                'currency' => $wallet->currency ?? ($merchant->default_currency ?? 'TZS'),
                'status' => SettlementRequestStatus::PendingApproval,
                'memo' => $memo,
                'bank_name' => $merchant->settlement_bank_name,
                'bank_account_name' => $merchant->settlement_account_name,
                'bank_account_number' => $merchant->settlement_account_number,
                'bank_branch' => $merchant->settlement_bank_branch,
                'requested_by' => $user->id,
            ]);

            $this->walletLedgerService->holdSettlementFunds(
                wallet: $wallet,
                amount: $totalDebit,
                currency: $settlement->currency,
                reference: $settlement->request_id,
            );

            return $settlement->fresh(self::RELATIONS);
        });

        try {
            $this->adminApprovalSmsNotifier->notifySettlementRequest($request);
        } catch (\Throwable $exception) {
            Log::warning('Settlement request admin SMS failed.', [
                'request_id' => $request->request_id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $request;
    }

    public function approve(SettlementRequest $settlement, AdminUser $admin): SettlementRequest
    {
        return DB::transaction(function () use ($settlement, $admin): SettlementRequest {
            $locked = $this->lockPending($settlement, 'approved');
            $locked->load('wallet');

            $this->walletLedgerService->debitSettlementFunds(
                wallet: $locked->wallet,
                amount: (string) $locked->total_debit,
                currency: $locked->currency,
                reference: $locked->request_id,
            );

            $locked->update([
                'status' => SettlementRequestStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh(self::RELATIONS);
        });
    }

    public function reject(SettlementRequest $settlement, AdminUser $admin, ?string $reason = null): SettlementRequest
    {
        return $this->closeWithRelease($settlement, SettlementRequestStatus::Rejected, 'rejected', [
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
    }

    public function cancelByMerchant(SettlementRequest $settlement): SettlementRequest
    {
        return $this->closeWithRelease($settlement, SettlementRequestStatus::Cancelled, 'cancelled');
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

    public function findForMerchantOrFail(int $id, Merchant $merchant): SettlementRequest
    {
        return SettlementRequest::query()
            ->where('merchant_id', $merchant->id)
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    public function findOrFail(int $id): SettlementRequest
    {
        return SettlementRequest::query()->with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * Commission accrued on successful collections into this wallet's network,
     * minus commission already claimed by pending or approved settlements.
     */
    public function outstandingCommission(Wallet $wallet, ?MerchantCommission $commission): string
    {
        $accrued = $this->accruedCommission($wallet, $commission);

        $claimed = (string) SettlementRequest::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('status', SettlementRequestStatus::commissionClaimingValues())
            ->sum('commission_amount');

        return $this->subtractFloorZero($accrued, number_format((float) $claimed, 4, '.', ''));
    }

    private function accruedCommission(Wallet $wallet, ?MerchantCommission $commission): string
    {
        if ($wallet->provider_network_id === null) {
            return '0.0000';
        }

        $collections = Transaction::query()
            ->where('merchant_id', $wallet->merchant_id)
            ->where('provider_network_id', $wallet->provider_network_id)
            ->where('operation', PaymentOperation::C2bPush)
            ->where('status', TransactionStatus::Success);

        return $this->commissionFeeCalculator->totalFeeForQuery($collections, $commission);
    }

    private function collectionCommission(Merchant $merchant): ?MerchantCommission
    {
        return MerchantCommission::query()
            ->where('merchant_id', $merchant->id)
            ->where('operation', PaymentOperation::C2bPush)
            ->first();
    }

    /**
     * @return Collection<int, Wallet>
     */
    private function collectionWallets(Merchant $merchant): Collection
    {
        return Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->where('wallet_type', WalletType::CollectionLeaf)
            ->where('is_active', true)
            ->with(['balance', 'providerNetwork'])
            ->orderBy('id')
            ->get();
    }

    private function resolveCollectionWallet(Merchant $merchant, int $walletId): Wallet
    {
        $wallet = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->with('balance')
            ->find($walletId);

        if ($wallet === null || ! $wallet->is_active || $wallet->wallet_type !== WalletType::CollectionLeaf) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'Settlements can only be requested from your active collection wallets.',
                httpStatus: 422,
            );
        }

        return $wallet;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function closeWithRelease(
        SettlementRequest $settlement,
        SettlementRequestStatus $status,
        string $action,
        array $attributes = [],
    ): SettlementRequest {
        return DB::transaction(function () use ($settlement, $status, $action, $attributes): SettlementRequest {
            $locked = $this->lockPending($settlement, $action);
            $locked->load('wallet');

            $this->walletLedgerService->releaseSettlementFunds(
                wallet: $locked->wallet,
                amount: (string) $locked->total_debit,
                currency: $locked->currency,
                reference: $locked->request_id,
            );

            $locked->update(['status' => $status, ...$attributes]);

            return $locked->fresh(self::RELATIONS);
        });
    }

    private function lockPending(SettlementRequest $settlement, string $action): SettlementRequest
    {
        $locked = SettlementRequest::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== SettlementRequestStatus::PendingApproval) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                "Only pending settlement requests can be {$action}.",
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

        return SettlementRequest::query()
            ->with(self::RELATIONS)
            ->when(
                isset($filters['status']) && $filters['status'] !== '',
                fn ($query) => $query->where('status', strtoupper((string) $filters['status'])),
            )
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $search = (string) $filters['search'];

                $query->where(function ($inner) use ($search): void {
                    $inner->where('request_id', 'like', "%{$search}%")
                        ->orWhere('memo', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['from']) && $filters['from'] !== '', function ($query) use ($filters, $tz): void {
                $query->where('created_at', '>=', Carbon::parse((string) $filters['from'], $tz)->startOfDay()->utc());
            })
            ->when(isset($filters['to']) && $filters['to'] !== '', function ($query) use ($filters, $tz): void {
                $query->where('created_at', '<=', Carbon::parse((string) $filters['to'], $tz)->endOfDay()->utc());
            });
    }

    private function subtractFloorZero(string $left, string $right): string
    {
        $result = bcsub($left, $right, 4);

        return bccomp($result, '0', 4) < 0 ? '0.0000' : $result;
    }

    private function generateRequestId(): string
    {
        return 'STR-'.strtoupper(Str::random(16));
    }
}
