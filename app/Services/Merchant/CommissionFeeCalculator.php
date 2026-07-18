<?php

namespace App\Services\Merchant;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\Transaction;

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
