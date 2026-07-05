<?php

namespace App\Services\Payment;

use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionHistoryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForMerchant(Merchant $merchant, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['perPage'] ?? 25);

        return Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['operation']), fn ($q) => $q->where('operation', $filters['operation']))
            ->when(isset($filters['reference']), fn ($q) => $q->where('reference', $filters['reference']))
            ->when(isset($filters['providerCode']), function ($q) use ($filters) {
                $code = ProviderCode::tryFrom(strtoupper((string) $filters['providerCode']));

                if ($code !== null) {
                    $q->whereHas('providerNetwork', fn ($nq) => $nq->where('code', $code));
                }
            })
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->with(['providerNetwork', 'paymentProvider'])
            ->latest()
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
        $perPage = (int) ($filters['perPage'] ?? 25);

        return Transaction::query()
            ->when(isset($filters['merchantId']), fn ($q) => $q->where('merchant_id', $filters['merchantId']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['operation']), fn ($q) => $q->where('operation', $filters['operation']))
            ->when(isset($filters['reference']), fn ($q) => $q->where('reference', $filters['reference']))
            ->when(isset($filters['providerCode']), function ($q) use ($filters) {
                $code = ProviderCode::tryFrom(strtoupper((string) $filters['providerCode']));

                if ($code !== null) {
                    $q->whereHas('providerNetwork', fn ($nq) => $nq->where('code', $code));
                }
            })
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $search = (string) $filters['search'];
                $q->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%")
                        ->orWhere('msisdn', 'like', "%{$search}%")
                        ->orWhere('provider_transaction_id', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->with(['providerNetwork', 'paymentProvider', 'merchant'])
            ->latest()
            ->paginate($perPage);
    }
}
