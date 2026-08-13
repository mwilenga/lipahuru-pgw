<?php

namespace App\Services\Wallet;

use App\Enums\FloatTopupSource;
use App\Enums\FloatTopupStatus;
use App\Enums\GatewayErrorCode;
use App\Enums\ProviderCode;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\AdminUser;
use App\Models\FloatTopup;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\ProviderNetwork;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FloatTopupService
{
    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    /**
     * @param  list<array{providerCode: string, amount: numeric-string|float|int|string}>  $items
     */
    public function requestByMerchant(
        Merchant $merchant,
        MerchantUser $user,
        array $items,
        ?string $reference = null,
        ?string $notes = null,
    ): FloatTopup {
        return $this->createTopup(
            merchant: $merchant,
            items: $items,
            source: FloatTopupSource::Merchant,
            status: FloatTopupStatus::Pending,
            requestedByType: MerchantUser::class,
            requestedById: $user->id,
            reference: $reference,
            notes: $notes,
            creditImmediately: false,
        );
    }

    /**
     * @param  list<array{providerCode: string, amount: numeric-string|float|int|string}>  $items
     */
    public function createDirectByAdmin(
        Merchant $merchant,
        AdminUser $admin,
        array $items,
        ?string $reference = null,
        ?string $notes = null,
    ): FloatTopup {
        return $this->createTopup(
            merchant: $merchant,
            items: $items,
            source: FloatTopupSource::Admin,
            status: FloatTopupStatus::Approved,
            requestedByType: AdminUser::class,
            requestedById: $admin->id,
            reference: $reference,
            notes: $notes,
            creditImmediately: true,
            reviewedBy: $admin,
        );
    }

    public function approve(FloatTopup $topup, AdminUser $admin): FloatTopup
    {
        return DB::transaction(function () use ($topup, $admin): FloatTopup {
            $locked = FloatTopup::query()->whereKey($topup->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FloatTopupStatus::Pending) {
                throw new GatewayException(
                    GatewayErrorCode::GeneralError,
                    'Only pending float topups can be approved.',
                    httpStatus: 422,
                );
            }

            $locked->load(['items.wallet']);

            foreach ($locked->items as $item) {
                $this->walletLedgerService->creditDisbursementFloat(
                    wallet: $item->wallet,
                    amount: (string) $item->amount,
                    currency: $locked->currency,
                    reference: $locked->topup_id,
                    description: 'Disbursement float topup approved',
                );
            }

            $locked->update([
                'status' => FloatTopupStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh(['merchant', 'items.providerNetwork', 'reviewer']);
        });
    }

    public function reject(FloatTopup $topup, AdminUser $admin, ?string $reason = null): FloatTopup
    {
        return DB::transaction(function () use ($topup, $admin, $reason): FloatTopup {
            $locked = FloatTopup::query()->whereKey($topup->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FloatTopupStatus::Pending) {
                throw new GatewayException(
                    GatewayErrorCode::GeneralError,
                    'Only pending float topups can be rejected.',
                    httpStatus: 422,
                );
            }

            $locked->update([
                'status' => FloatTopupStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh(['merchant', 'items.providerNetwork', 'reviewer']);
        });
    }

    public function listForMerchant(Merchant $merchant, int $perPage = 25): LengthAwarePaginator
    {
        return FloatTopup::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items.providerNetwork', 'reviewer'])
            ->latest()
            ->paginate($perPage);
    }

    public function listForAdmin(?string $status = null, ?int $merchantId = null, int $perPage = 25): LengthAwarePaginator
    {
        return FloatTopup::query()
            ->with(['merchant', 'items.providerNetwork', 'reviewer'])
            ->when($status !== null && $status !== '', function ($query) use ($status): void {
                $query->where('status', strtoupper($status));
            })
            ->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId))
            ->latest()
            ->paginate($perPage);
    }

    public function findForMerchantOrFail(int $id, Merchant $merchant): FloatTopup
    {
        return FloatTopup::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items.providerNetwork', 'reviewer'])
            ->findOrFail($id);
    }

    public function findOrFail(int $id): FloatTopup
    {
        return FloatTopup::query()
            ->with(['merchant', 'items.providerNetwork', 'reviewer'])
            ->findOrFail($id);
    }

    /**
     * @param  list<array{providerCode: string, amount: numeric-string|float|int|string}>  $items
     */
    private function createTopup(
        Merchant $merchant,
        array $items,
        FloatTopupSource $source,
        FloatTopupStatus $status,
        string $requestedByType,
        int $requestedById,
        ?string $reference,
        ?string $notes,
        bool $creditImmediately,
        ?AdminUser $reviewedBy = null,
    ): FloatTopup {
        $resolvedItems = $this->resolveItems($merchant, $items);
        $totalAmount = '0.0000';

        foreach ($resolvedItems as $item) {
            $totalAmount = bcadd($totalAmount, $item['amount'], 4);
        }

        return DB::transaction(function () use (
            $merchant,
            $resolvedItems,
            $source,
            $status,
            $requestedByType,
            $requestedById,
            $reference,
            $notes,
            $creditImmediately,
            $reviewedBy,
            $totalAmount,
        ): FloatTopup {
            $topup = FloatTopup::query()->create([
                'topup_id' => $this->generateTopupId(),
                'merchant_id' => $merchant->id,
                'source' => $source,
                'status' => $status,
                'currency' => $merchant->default_currency ?? 'TZS',
                'total_amount' => $totalAmount,
                'reference' => $reference,
                'notes' => $notes,
                'requested_by_type' => $requestedByType,
                'requested_by_id' => $requestedById,
                'reviewed_by' => $reviewedBy?->id,
                'reviewed_at' => $creditImmediately ? now() : null,
            ]);

            foreach ($resolvedItems as $item) {
                $topup->items()->create([
                    'provider_network_id' => $item['network']->id,
                    'wallet_id' => $item['wallet']->id,
                    'amount' => $item['amount'],
                ]);
            }

            if ($creditImmediately) {
                $topup->load('items.wallet');

                foreach ($topup->items as $item) {
                    $this->walletLedgerService->creditDisbursementFloat(
                        wallet: $item->wallet,
                        amount: (string) $item->amount,
                        currency: $topup->currency,
                        reference: $topup->topup_id,
                        description: 'Direct admin disbursement float topup',
                    );
                }
            }

            return $topup->fresh(['merchant', 'items.providerNetwork', 'reviewer']);
        });
    }

    /**
     * @param  list<array{providerCode: string, amount: numeric-string|float|int|string}>  $items
     * @return list<array{network: ProviderNetwork, wallet: Wallet, amount: string}>
     */
    private function resolveItems(Merchant $merchant, array $items): array
    {
        if ($items === []) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'At least one network amount is required.',
                httpStatus: 422,
            );
        }

        $seen = [];
        $resolved = [];

        foreach ($items as $item) {
            $providerCode = strtoupper((string) ($item['providerCode'] ?? ''));
            $amount = number_format((float) ($item['amount'] ?? 0), 4, '.', '');

            if ($providerCode === '' || ! in_array($providerCode, ProviderCode::values(), true)) {
                throw new GatewayException(
                    GatewayErrorCode::UnsupportedProvider,
                    "Unsupported providerCode: {$providerCode}",
                    httpStatus: 422,
                );
            }

            if (isset($seen[$providerCode])) {
                throw new GatewayException(
                    GatewayErrorCode::InvalidPayload,
                    "Duplicate providerCode: {$providerCode}",
                    httpStatus: 422,
                );
            }

            if (bccomp($amount, '100.0000', 4) < 0) {
                throw new GatewayException(
                    GatewayErrorCode::InvalidPayload,
                    "Amount for {$providerCode} must be at least 100.",
                    httpStatus: 422,
                );
            }

            $network = ProviderNetwork::query()
                ->where('code', $providerCode)
                ->where('is_active', true)
                ->first();

            if ($network === null) {
                throw new GatewayException(
                    GatewayErrorCode::UnsupportedProvider,
                    "Provider network not found or inactive: {$providerCode}",
                    httpStatus: 422,
                );
            }

            $wallet = Wallet::query()
                ->where('merchant_id', $merchant->id)
                ->where('provider_network_id', $network->id)
                ->where('wallet_type', WalletType::DisbursementLeaf)
                ->where('is_active', true)
                ->first();

            if ($wallet === null) {
                throw new GatewayException(
                    GatewayErrorCode::GeneralError,
                    "Disbursement wallet not provisioned for {$providerCode}",
                    httpStatus: 422,
                );
            }

            $seen[$providerCode] = true;
            $resolved[] = [
                'network' => $network,
                'wallet' => $wallet,
                'amount' => $amount,
            ];
        }

        return $resolved;
    }

    private function generateTopupId(): string
    {
        return 'TOP-'.strtoupper(Str::random(16));
    }
}
