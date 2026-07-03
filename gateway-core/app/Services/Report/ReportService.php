<?php

namespace App\Services\Report;

use App\Enums\TransactionStatus;
use App\Models\DailyMerchantSummary;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function getMerchantSummary(int $merchantId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->subDays(30)->startOfDay();
        $to ??= now()->endOfDay();

        $stored = DailyMerchantSummary::query()
            ->where('merchant_id', $merchantId)
            ->whereBetween('summary_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('summary_date')
            ->get();

        if ($stored->isNotEmpty()) {
            return [
                'merchantId' => $merchantId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'totalTransactions' => $stored->sum('total_transactions'),
                'successfulTransactions' => $stored->sum('successful_transactions'),
                'failedTransactions' => $stored->sum('failed_transactions'),
                'totalVolume' => (string) $stored->sum('total_volume'),
                'successfulVolume' => (string) $stored->sum('successful_volume'),
                'currency' => $stored->first()->currency ?? config('payment-gateway.default_currency', 'TZS'),
                'daily' => $stored->map(fn (DailyMerchantSummary $row) => [
                    'date' => $row->summary_date->toDateString(),
                    'totalTransactions' => $row->total_transactions,
                    'successfulTransactions' => $row->successful_transactions,
                    'failedTransactions' => $row->failed_transactions,
                    'totalVolume' => (string) $row->total_volume,
                    'successfulVolume' => (string) $row->successful_volume,
                ])->values()->all(),
            ];
        }

        return $this->computeMerchantSummary($merchantId, $from, $to);
    }

    public function aggregateDailySummary(Merchant $merchant, Carbon $date): DailyMerchantSummary
    {
        $transactions = Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->whereDate('created_at', $date)
            ->get();

        $successful = $transactions->where('status', TransactionStatus::Success);
        $failed = $transactions->where('status', TransactionStatus::Failed);

        return DailyMerchantSummary::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'summary_date' => $date->toDateString(),
            ],
            [
                'total_transactions' => $transactions->count(),
                'successful_transactions' => $successful->count(),
                'failed_transactions' => $failed->count(),
                'total_volume' => $transactions->reduce(
                    fn (string $carry, Transaction $txn) => bcadd($carry, (string) $txn->amount, 4),
                    '0.0000',
                ),
                'successful_volume' => $successful->reduce(
                    fn (string $carry, Transaction $txn) => bcadd($carry, (string) $txn->amount, 4),
                    '0.0000',
                ),
                'currency' => $merchant->default_currency,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeMerchantSummary(int $merchantId, Carbon $from, Carbon $to): array
    {
        $transactions = Transaction::query()
            ->where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $successful = $transactions->where('status', TransactionStatus::Success);
        $failed = $transactions->where('status', TransactionStatus::Failed);

        return [
            'merchantId' => $merchantId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totalTransactions' => $transactions->count(),
            'successfulTransactions' => $successful->count(),
            'failedTransactions' => $failed->count(),
            'totalVolume' => (string) $transactions->reduce(
                fn (string $carry, Transaction $txn) => bcadd($carry, (string) $txn->amount, 4),
                '0.0000',
            ),
            'successfulVolume' => (string) $successful->reduce(
                fn (string $carry, Transaction $txn) => bcadd($carry, (string) $txn->amount, 4),
                '0.0000',
            ),
            'currency' => Merchant::query()->find($merchantId)?->default_currency
                ?? config('payment-gateway.default_currency', 'TZS'),
            'daily' => [],
        ];
    }
}
