<?php

namespace App\Services\Merchant;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class CommissionFeeCalculator
{
    /**
     * @return array{fee: string, net: string}
     */
    public function forTransaction(Transaction $transaction): array
    {
        $amount = (float) $transaction->amount;
        $fee = $this->feeFor(
            $transaction->merchant,
            $transaction->operation,
            $amount,
        );

        return [
            'fee' => number_format($fee, 4, '.', ''),
            'net' => number_format(max(0, $amount - $fee), 4, '.', ''),
        ];
    }

    public function feeFor(?Merchant $merchant, ?PaymentOperation $operation, float $amount): float
    {
        if ($merchant === null || $operation === null || $amount <= 0) {
            return 0.0;
        }

        $commission = $this->resolveCommission($merchant, $operation);

        if ($commission === null) {
            return 0.0;
        }

        $value = (float) $commission->value;

        if ($value <= 0) {
            return 0.0;
        }

        $fee = match ($commission->commission_type) {
            CommissionType::Fixed => $value,
            CommissionType::Percent => $amount * ($value / 100),
            default => 0.0,
        };

        return round(min($fee, $amount), 4);
    }

    /**
     * Sum of per-transaction fees over a transaction query, matching feeFor() per row.
     *
     * @param  Builder<Transaction>  $transactions
     */
    public function totalFeeForQuery(Builder $transactions, ?MerchantCommission $commission): string
    {
        $value = number_format((float) ($commission?->value ?? 0), 4, '.', '');

        if ($commission === null || bccomp($value, '0', 4) <= 0) {
            return '0.0000';
        }

        if ($commission->commission_type === CommissionType::Fixed) {
            $sum = (clone $transactions)
                ->selectRaw('COALESCE(SUM(CASE WHEN amount < ? THEN amount ELSE ? END), 0) AS fee', [$value, $value])
                ->value('fee');

            return number_format((float) $sum, 4, '.', '');
        }

        if ($commission->commission_type !== CommissionType::Percent) {
            return '0.0000';
        }

        $rate = bccomp($value, '100', 4) > 0 ? '100' : $value;
        $gross = number_format((float) (clone $transactions)->sum('amount'), 4, '.', '');

        return bcdiv(bcmul($gross, $rate, 8), '100', 4);
    }

    /**
     * Count, gross and fee totals for the SUCCESS rows of a transaction query, using each
     * row's merchant commission for its operation (matching feeFor() per row).
     *
     * @param  Builder<Transaction>  $transactions
     * @return array{count: int, amount: string, fee: string, net: string}
     */
    public function successTotalsForQuery(Builder $transactions): array
    {
        return $this->formatTotals($this->successTotalsQuery($transactions)->first());
    }

    /**
     * Same as successTotalsForQuery(), grouped per merchant.
     *
     * @param  Builder<Transaction>  $transactions
     * @return array<int, array{count: int, amount: string, fee: string, net: string}>
     */
    public function successTotalsByMerchant(Builder $transactions): array
    {
        $totals = [];

        $rows = $this->successTotalsQuery($transactions)
            ->addSelect('t.merchant_id')
            ->groupBy('t.merchant_id')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->merchant_id] = $this->formatTotals($row);
        }

        return $totals;
    }

    /**
     * @param  Builder<Transaction>  $transactions
     */
    private function successTotalsQuery(Builder $transactions): QueryBuilder
    {
        $successful = (clone $transactions)
            ->where('status', TransactionStatus::Success)
            ->select(['transactions.merchant_id', 'transactions.operation', 'transactions.amount']);

        return DB::query()
            ->fromSub($successful, 't')
            ->leftJoin('merchant_commissions as mc', function ($join) {
                $join->on('mc.merchant_id', '=', 't.merchant_id')
                    ->on('mc.operation', '=', 't.operation');
            })
            ->selectRaw('COUNT(*) AS success_count')
            ->selectRaw('COALESCE(SUM(t.amount), 0) AS success_amount')
            ->selectRaw(
                'COALESCE(SUM(CASE
                    WHEN mc.value IS NULL OR mc.value <= 0 THEN 0
                    WHEN mc.commission_type = ? THEN CASE WHEN t.amount < mc.value THEN t.amount ELSE mc.value END
                    WHEN mc.commission_type = ? THEN t.amount * CASE WHEN mc.value > 100 THEN 100 ELSE mc.value END / 100
                    ELSE 0
                END), 0) AS fee_amount',
                [CommissionType::Fixed->value, CommissionType::Percent->value],
            );
    }

    /**
     * @return array{count: int, amount: string, fee: string, net: string}
     */
    private function formatTotals(?object $row): array
    {
        $amount = number_format((float) ($row->success_amount ?? 0), 4, '.', '');
        $fee = number_format((float) ($row->fee_amount ?? 0), 4, '.', '');
        $net = bcsub($amount, $fee, 4);

        return [
            'count' => (int) ($row->success_count ?? 0),
            'amount' => $amount,
            'fee' => $fee,
            'net' => bccomp($net, '0', 4) < 0 ? '0.0000' : $net,
        ];
    }

    private function resolveCommission(Merchant $merchant, PaymentOperation $operation): ?MerchantCommission
    {
        if ($merchant->relationLoaded('commissions')) {
            return $merchant->commissions->first(
                fn (MerchantCommission $row) => $row->operation === $operation,
            );
        }

        return MerchantCommission::query()
            ->where('merchant_id', $merchant->id)
            ->where('operation', $operation)
            ->first();
    }
}
