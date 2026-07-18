<?php

namespace App\Services\Payment;

use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TransactionHistoryService
{
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Transaction>
     */
    public function listPendingForPolling(): \Illuminate\Database\Eloquent\Collection
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Transaction>
     */
    public function listForReconciliation(): \Illuminate\Database\Eloquent\Collection
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
     * @return array{totalAmount: string, currency: string, count: int}
     */
    public function summarizeAll(array $filters = []): array
    {
        $query = $this->applyFilters(Transaction::query(), $filters);

        $count = (clone $query)->count();
        $totalAmount = (clone $query)->sum('amount');
        $currency = (string) config('payment-gateway.default_currency', 'TZS');

        return [
            'totalAmount' => number_format((float) $totalAmount, 4, '.', ''),
            'currency' => $currency,
            'count' => $count,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{totalAmount: string, currency: string, count: int}
     */
    public function summarizeForMerchant(Merchant $merchant, array $filters = []): array
    {
        $query = $this->applyFilters(
            Transaction::query()->where('merchant_id', $merchant->id),
            $filters,
        );

        $count = (clone $query)->count();
        $totalAmount = (clone $query)->sum('amount');

        return [
            'totalAmount' => number_format((float) $totalAmount, 4, '.', ''),
            'currency' => $merchant->default_currency,
            'count' => $count,
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
