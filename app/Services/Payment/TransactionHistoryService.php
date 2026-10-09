<?php

namespace App\Services\Payment;

use App\Enums\ProviderCode;
use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Services\Merchant\CommissionFeeCalculator;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TransactionHistoryService
{
    public function __construct(
        private readonly CommissionFeeCalculator $commissionFeeCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForMerchant(Merchant $merchant, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['perPage'] ?? 10);

        return $this->applyFilters(
            Transaction::query()->where('merchant_id', $merchant->id),
            $filters,
        )
            ->with(['providerNetwork', 'paymentProvider', 'merchant.commissions'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function listPendingForPolling(): Collection
    {
        $afterSeconds = (int) config('payment-gateway.poll_pending_after_seconds', 120);

        return Transaction::query()
            ->where('status', TransactionStatus::PendingFinal)
            ->where('updated_at', '<=', now()->subSeconds($afterSeconds))
            ->with(['providerNetwork', 'paymentProvider'])
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function listForReconciliation(): Collection
    {
        return Transaction::query()
            ->where('status', TransactionStatus::Reconciling)
            ->with(['providerNetwork', 'paymentProvider'])
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listAll(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['perPage'] ?? 10);

        return $this->applyFilters(Transaction::query(), $filters)
            ->with(['providerNetwork', 'paymentProvider', 'merchant.commissions'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{totalAmount: string, currency: string, count: int, successCount: int, successAmount: string, feeAmount: string, netAmount: string}
     */
    public function summarizeAll(array $filters = []): array
    {
        return $this->summarize(
            $this->applyFilters(Transaction::query(), $filters),
            (string) config('payment-gateway.default_currency', 'TZS'),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{totalAmount: string, currency: string, count: int, successCount: int, successAmount: string, feeAmount: string, netAmount: string}
     */
    public function summarizeForMerchant(Merchant $merchant, array $filters = []): array
    {
        return $this->summarize(
            $this->applyFilters(Transaction::query()->where('merchant_id', $merchant->id), $filters),
            (string) $merchant->default_currency,
        );
    }

    /**
     * Charges and net only cover SUCCESS transactions; totalAmount/count cover all matching rows.
     *
     * @param  Builder<Transaction>  $query
     * @return array{totalAmount: string, currency: string, count: int, successCount: int, successAmount: string, feeAmount: string, netAmount: string}
     */
    private function summarize(Builder $query, string $currency): array
    {
        $success = $this->commissionFeeCalculator->successTotalsForQuery($query);

        return [
            'totalAmount' => number_format((float) (clone $query)->sum('amount'), 4, '.', ''),
            'currency' => $currency,
            'count' => (clone $query)->count(),
            'successCount' => $success['count'],
            'successAmount' => $success['amount'],
            'feeAmount' => $success['fee'],
            'netAmount' => $success['net'],
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(isset($filters['merchantId']), fn ($q) => $q->where('merchant_id', $filters['merchantId']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['operation']) && $filters['operation'] !== '', fn ($q) => $q->where('operation', $filters['operation']))
            ->when(isset($filters['reference']) && $filters['reference'] !== '', fn ($q) => $q->where('reference', 'like', '%'.$filters['reference'].'%'))
            ->when(isset($filters['msisdn']) && $filters['msisdn'] !== '', fn ($q) => $q->where('msisdn', 'like', '%'.$filters['msisdn'].'%'))
            ->when(isset($filters['providerReceiptNo']) && $filters['providerReceiptNo'] !== '', function ($q) use ($filters) {
                $q->where('provider_receipt_no', 'like', '%'.$filters['providerReceiptNo'].'%');
            })
            ->when(isset($filters['providerCode']) && $filters['providerCode'] !== '', function ($q) use ($filters) {
                $code = ProviderCode::tryFrom(strtoupper((string) $filters['providerCode']));

                if ($code !== null) {
                    $q->whereHas('providerNetwork', fn ($nq) => $nq->where('code', $code));
                }
            })
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($q) use ($filters) {
                $search = (string) $filters['search'];
                $q->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%")
                        ->orWhere('request_id', 'like', "%{$search}%")
                        ->orWhere('msisdn', 'like', "%{$search}%")
                        ->orWhere('provider_transaction_id', 'like', "%{$search}%")
                        ->orWhere('provider_receipt_no', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['from']) && $filters['from'] !== '', function ($q) use ($filters) {
                $start = Carbon::parse((string) $filters['from'], $this->filterTimezone())
                    ->startOfDay()
                    ->utc();
                $q->where('created_at', '>=', $start);
            })
            ->when(isset($filters['to']) && $filters['to'] !== '', function ($q) use ($filters) {
                $end = Carbon::parse((string) $filters['to'], $this->filterTimezone())
                    ->endOfDay()
                    ->utc();
                $q->where('created_at', '<=', $end);
            });
    }

    private function filterTimezone(): string
    {
        return (string) config('payment-gateway.filter_timezone', 'Africa/Dar_es_Salaam');
    }
}
