<?php

namespace App\Services\Portal;

use App\Enums\MerchantStatus;
use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Http\Resources\TransactionResource;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Payment\TransactionHistoryService;
use Carbon\Carbon;

class AdminPortalService
{
    public function __construct(
        private readonly TransactionHistoryService $transactionHistoryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        [$todayStart, $todayEnd] = $this->todayBounds();
        $currency = (string) config('payment-gateway.default_currency', 'TZS');

        $collectionsToday = (float) Transaction::query()
            ->where('operation', PaymentOperation::C2bPush)
            ->where('status', TransactionStatus::Success)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('amount');

        $disbursementsToday = (float) Transaction::query()
            ->where('operation', PaymentOperation::B2cDisbursement)
            ->where('status', TransactionStatus::Success)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('amount');

        $pending = Transaction::query()
            ->whereIn('status', [
                TransactionStatus::PendingFinal,
                TransactionStatus::Acknowledged,
                TransactionStatus::FundsReserved,
            ])
            ->count();

        $failed = Transaction::query()
            ->where('status', TransactionStatus::Failed)
            ->count();

        $parentWallets = Wallet::query()
            ->where('wallet_type', WalletType::MerchantParent)
            ->with('balance')
            ->get();

        $parentAvailable = '0.0000';
        $parentReserved = '0.0000';
        $parentTotal = '0.0000';

        foreach ($parentWallets as $wallet) {
            $parentAvailable = bcadd($parentAvailable, (string) ($wallet->balance?->available ?? '0'), 4);
            $parentReserved = bcadd($parentReserved, (string) ($wallet->balance?->reserved ?? '0'), 4);
            $parentTotal = bcadd($parentTotal, (string) ($wallet->balance?->total ?? '0'), 4);
        }

        $providerTotals = Wallet::query()
            ->where('wallet_type', WalletType::ProviderTotal)
            ->with(['balance', 'providerNetwork'])
            ->get()
            ->groupBy('provider_network_id')
            ->map(function ($wallets) {
                /** @var \Illuminate\Support\Collection<int, Wallet> $wallets */
                $first = $wallets->first();
                $available = '0.0000';
                $total = '0.0000';

                foreach ($wallets as $wallet) {
                    $available = bcadd($available, (string) ($wallet->balance?->available ?? '0'), 4);
                    $total = bcadd($total, (string) ($wallet->balance?->total ?? '0'), 4);
                }

                return [
                    'providerCode' => $first?->providerNetwork?->code?->value,
                    'name' => $first?->providerNetwork?->name ?? $first?->name ?? 'Provider',
                    'available' => $available,
                    'total' => $total,
                    'currency' => $first?->currency ?? 'TZS',
                ];
            })
            ->values()
            ->all();

        $recent = $this->transactionHistoryService->listAll(['perPage' => 8]);

        return [
            'collectionsToday' => number_format($collectionsToday, 4, '.', ''),
            'disbursementsToday' => number_format($disbursementsToday, 4, '.', ''),
            'pendingCount' => $pending,
            'failedCount' => $failed,
            'merchantCount' => Merchant::query()->count(),
            'activeMerchantCount' => Merchant::query()->where('status', MerchantStatus::Active)->count(),
            'parentWallet' => [
                'available' => $parentAvailable,
                'reserved' => $parentReserved,
                'total' => $parentTotal,
                'currency' => $currency,
            ],
            'providerWallets' => $providerTotals,
            'recentTransactions' => TransactionResource::collection($recent->items())->resolve(),
            'currency' => $currency,
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
