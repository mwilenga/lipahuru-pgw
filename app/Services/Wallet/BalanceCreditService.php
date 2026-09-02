<?php

namespace App\Services\Wallet;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Models\AdminUser;
use App\Models\BalanceCredit;
use App\Models\Merchant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BalanceCreditService
{
    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
        private readonly \App\Repositories\Contracts\WalletRepositoryInterface $walletRepository,
    ) {}

    public function create(
        Merchant $merchant,
        AdminUser $admin,
        string $amount,
        ?string $reference = null,
        ?string $notes = null,
    ): BalanceCredit {
        $normalized = number_format((float) $amount, 4, '.', '');

        if (bccomp($normalized, '100.0000', 4) < 0) {
            throw new GatewayException(
                GatewayErrorCode::InvalidPayload,
                'Credit amount must be at least 100.',
                httpStatus: 422,
            );
        }

        $wallet = $this->walletRepository->findMerchantBalance($merchant->id);

        if ($wallet === null) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'Merchant balance wallet not provisioned',
                httpStatus: 422,
            );
        }

        return DB::transaction(function () use ($merchant, $admin, $normalized, $reference, $notes, $wallet): BalanceCredit {
            $credit = BalanceCredit::query()->create([
                'credit_id' => 'CRD-'.strtoupper(Str::random(16)),
                'merchant_id' => $merchant->id,
                'amount' => $normalized,
                'currency' => $merchant->default_currency ?? 'TZS',
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $admin->id,
            ]);

            $this->walletLedgerService->creditMerchantBalance(
                wallet: $wallet,
                amount: $normalized,
                currency: $credit->currency,
                reference: $credit->credit_id,
                description: 'Admin balance credit',
            );

            return $credit->fresh(['merchant', 'creator']);
        });
    }

    public function listForAdmin(?int $merchantId = null, int $perPage = 25): LengthAwarePaginator
    {
        return BalanceCredit::query()
            ->with(['merchant', 'creator'])
            ->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId))
            ->latest()
            ->paginate($perPage);
    }
}
