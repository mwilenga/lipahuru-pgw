<?php

namespace App\Services\Portal;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Http\Resources\TransactionResource;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Payment\TransactionHistoryService;
use App\Services\Wallet\WalletQueryService;
use Carbon\Carbon;

class MerchantPortalService
{
    public function __construct(
        private readonly WalletQueryService $walletQueryService,
        private readonly TransactionHistoryService $transactionHistoryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Merchant $merchant): array
    {
        [$todayStart, $todayEnd] = $this->todayBounds();

        $collectionsToday = (float) Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->where('operation', PaymentOperation::C2bPush)
            ->where('status', TransactionStatus::Success)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('amount');

        $disbursementsToday = (float) Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->where('operation', PaymentOperation::B2cDisbursement)
            ->where('status', TransactionStatus::Success)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('amount');

        $pending = Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('status', [
                TransactionStatus::PendingFinal,
                TransactionStatus::Acknowledged,
                TransactionStatus::FundsReserved,
            ])
            ->count();

        $failed = Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', TransactionStatus::Failed)
            ->count();

        $parentWallet = Wallet::query()
            ->where('merchant_id', $merchant->id)
            ->where('wallet_type', WalletType::MerchantParent)
            ->with('balance')
            ->first();

        $wallets = $this->walletQueryService->listForMerchant($merchant);
        $providerTotals = $wallets->where('wallet_type', WalletType::ProviderTotal)->values();

        $recent = $this->transactionHistoryService->listForMerchant($merchant, ['perPage' => 8]);

        return [
            'collectionsToday' => number_format($collectionsToday, 4, '.', ''),
            'disbursementsToday' => number_format($disbursementsToday, 4, '.', ''),
            'pendingCount' => $pending,
            'failedCount' => $failed,
            'parentWallet' => $parentWallet ? [
                'available' => (string) ($parentWallet->balance?->available ?? '0.0000'),
                'reserved' => (string) ($parentWallet->balance?->reserved ?? '0.0000'),
                'total' => (string) ($parentWallet->balance?->total ?? '0.0000'),
                'currency' => $parentWallet->currency,
            ] : null,
            'providerWallets' => $providerTotals->map(fn ($wallet) => [
                'providerCode' => $wallet->providerNetwork?->code?->value,
                'name' => $wallet->name,
                'available' => (string) ($wallet->balance?->available ?? '0.0000'),
                'total' => (string) ($wallet->balance?->total ?? '0.0000'),
                'currency' => $wallet->currency,
            ])->values()->all(),
            'recentTransactions' => TransactionResource::collection($recent->items())->resolve(),
            'currency' => $merchant->default_currency,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function todayBounds(): array
    {
        $tz = (string) config('payment-gateway.filter_timezone', 'Africa/Dar_es_Salaam');

        return [
            Carbon::now($tz)->startOfDay()->utc(),
            Carbon::now($tz)->endOfDay()->utc(),
        ];
    }
}
