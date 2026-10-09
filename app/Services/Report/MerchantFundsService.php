<?php

namespace App\Services\Report;

use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Enums\SettlementRequestStatus;
use App\Enums\WalletType;
use App\Models\Merchant;
use App\Models\ProviderNetwork;
use App\Models\SettlementRequest;
use App\Models\Transaction;
use App\Services\Merchant\CommissionFeeCalculator;
use Illuminate\Support\Facades\DB;

class MerchantFundsService
{
    public function __construct(
        private readonly CommissionFeeCalculator $commissionFeeCalculator,
    ) {}

    /**
     * All-time collection funds per merchant: net remaining = collected - charges - settled.
     *
     * @param  array{search?: string|null, providerCode?: string|null}  $filters
     * @return array{merchants: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function summarize(array $filters = []): array
    {
        $networkId = $this->networkIdFilter($filters['providerCode'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));

        $merchants = Merchant::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'status', 'default_currency']);

        $ids = $merchants->pluck('id')->all();

        $collections = $this->commissionFeeCalculator->successTotalsByMerchant(
            Transaction::query()
                ->whereIn('merchant_id', $ids)
                ->where('operation', PaymentOperation::C2bPush)
                ->when($networkId !== null, fn ($q) => $q->where('provider_network_id', $networkId)),
        );

        $settlements = SettlementRequest::query()
            ->whereIn('merchant_id', $ids)
            ->when($networkId !== null, fn ($q) => $q->whereHas(
                'wallet',
                fn ($w) => $w->where('provider_network_id', $networkId),
            ))
            ->groupBy('merchant_id')
            ->select('merchant_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS settled', [SettlementRequestStatus::Approved->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS pending', [SettlementRequestStatus::PendingApproval->value])
            ->get()
            ->keyBy('merchant_id');

        $balances = DB::table('wallets')
            ->join('wallet_balances', 'wallet_balances.wallet_id', '=', 'wallets.id')
            ->whereIn('wallets.merchant_id', $ids)
            ->where('wallets.wallet_type', WalletType::CollectionLeaf->value)
            ->when($networkId !== null, fn ($q) => $q->where('wallets.provider_network_id', $networkId))
            ->groupBy('wallets.merchant_id')
            ->select('wallets.merchant_id')
            ->selectRaw('COALESCE(SUM(wallet_balances.total), 0) AS balance')
            ->get()
            ->keyBy('merchant_id');

        $totals = [
            'collectionCount' => 0,
            'collected' => '0.0000',
            'charges' => '0.0000',
            'settled' => '0.0000',
            'pendingSettlement' => '0.0000',
            'netRemaining' => '0.0000',
            'walletBalance' => '0.0000',
            'currency' => (string) config('payment-gateway.default_currency', 'TZS'),
        ];

        $rows = [];

        foreach ($merchants as $merchant) {
            $collection = $collections[$merchant->id] ?? ['count' => 0, 'amount' => '0.0000', 'fee' => '0.0000'];
            $settlement = $settlements->get($merchant->id);
            $settled = $this->money($settlement?->settled);
            $pending = $this->money($settlement?->pending);
            $balance = $this->money($balances->get($merchant->id)?->balance);
            $netRemaining = bcsub(bcsub($collection['amount'], $collection['fee'], 4), $settled, 4);

            $rows[] = [
                'merchantId' => $merchant->id,
                'merchantName' => $merchant->name,
                'merchantEmail' => $merchant->email,
                'merchantStatus' => $merchant->status?->value,
                'currency' => $merchant->default_currency,
                'collectionCount' => $collection['count'],
                'collected' => $collection['amount'],
                'charges' => $collection['fee'],
                'settled' => $settled,
                'pendingSettlement' => $pending,
                'netRemaining' => $netRemaining,
                'walletBalance' => $balance,
            ];

            $totals['collectionCount'] += $collection['count'];
            $totals['collected'] = bcadd($totals['collected'], $collection['amount'], 4);
            $totals['charges'] = bcadd($totals['charges'], $collection['fee'], 4);
            $totals['settled'] = bcadd($totals['settled'], $settled, 4);
            $totals['pendingSettlement'] = bcadd($totals['pendingSettlement'], $pending, 4);
            $totals['netRemaining'] = bcadd($totals['netRemaining'], $netRemaining, 4);
            $totals['walletBalance'] = bcadd($totals['walletBalance'], $balance, 4);
        }

        return ['merchants' => $rows, 'totals' => $totals];
    }

    /**
     * Null when no provider filter applies; 0 when the code matches no network.
     */
    private function networkIdFilter(?string $providerCode): ?int
    {
        $code = ProviderCode::tryFrom(strtoupper(trim((string) $providerCode)));

        if ($code === null) {
            return null;
        }

        return (int) ProviderNetwork::query()->where('code', $code)->value('id');
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
