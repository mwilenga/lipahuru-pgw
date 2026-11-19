<?php

namespace App\Services\Provider;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Models\ProviderFeeRate;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ProviderFeeCalculator
{
    public const LEGACY_CUTOFF = '2026-10-01';

    public const B2C_FIXED_FEE = 500.0;

    /**
     * Live snapshot on success: C2B from active settings; B2C stays FIXED 500.
     *
     * @return array{fee: string, rate: ProviderFeeRate|null}
     */
    public function forTransaction(Transaction $transaction): array
    {
        if ($transaction->operation === PaymentOperation::B2cDisbursement) {
            return [
                'fee' => number_format(
                    $this->fixedB2cFee((float) $transaction->amount),
                    4,
                    '.',
                    '',
                ),
                'rate' => null,
            ];
        }

        $rate = $this->activeRate(
            $transaction->provider_network_id,
            $transaction->operation,
        );

        return [
            'fee' => number_format($this->feeFromRate($rate, (float) $transaction->amount), 4, '.', ''),
            'rate' => $rate,
        ];
    }

    /**
     * Backfill provider fee:
     * - before 2026-10-01: C2B 0.75% / B2C FIXED 500
     * - on/after 2026-10-01: C2B from configured network rates / B2C FIXED 500
     *
     * @return array{fee: string, rate: ProviderFeeRate|null}
     */
    public function backfillForTransaction(Transaction $transaction): array
    {
        $asOf = $transaction->finalized_at ?? $transaction->created_at ?? now();
        $amount = (float) $transaction->amount;
        $operation = $transaction->operation;

        if ($operation === PaymentOperation::B2cDisbursement) {
            return [
                'fee' => number_format($this->fixedB2cFee($amount), 4, '.', ''),
                'rate' => null,
            ];
        }

        if ($operation === PaymentOperation::C2bPush) {
            if ($this->isBeforeLegacyCutoff($asOf)) {
                return [
                    'fee' => number_format($this->legacyC2bFee($amount), 4, '.', ''),
                    'rate' => null,
                ];
            }

            $rate = $this->rateEffectiveAt(
                $transaction->provider_network_id,
                $operation,
                $asOf,
            );

            return [
                'fee' => number_format($this->feeFromRate($rate, $amount), 4, '.', ''),
                'rate' => $rate,
            ];
        }

        return [
            'fee' => '0.0000',
            'rate' => null,
        ];
    }

    public function activeRate(?int $providerNetworkId, ?PaymentOperation $operation): ?ProviderFeeRate
    {
        if ($providerNetworkId === null || $operation === null) {
            return null;
        }

        return ProviderFeeRate::query()
            ->where('provider_network_id', $providerNetworkId)
            ->where('operation', $operation)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function rateEffectiveAt(
        ?int $providerNetworkId,
        ?PaymentOperation $operation,
        CarbonInterface|string|null $asOf,
    ): ?ProviderFeeRate {
        if ($providerNetworkId === null || $operation === null) {
            return null;
        }

        $at = Carbon::parse($asOf ?? now());

        $rate = ProviderFeeRate::query()
            ->where('provider_network_id', $providerNetworkId)
            ->where('operation', $operation)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $rate ?? $this->activeRate($providerNetworkId, $operation);
    }

    public function feeFromRate(?ProviderFeeRate $rate, float $amount): float
    {
        if ($rate === null || $amount <= 0) {
            return 0.0;
        }

        $value = (float) $rate->value;

        if ($value <= 0) {
            return 0.0;
        }

        $fee = match ($rate->commission_type) {
            CommissionType::Fixed => $value,
            CommissionType::Percent => $amount * ($value / 100),
            default => 0.0,
        };

        return round(min($fee, $amount), 4);
    }

    public function legacyBackfillFee(?PaymentOperation $operation, float $amount): float
    {
        if ($operation === PaymentOperation::C2bPush) {
            return $this->legacyC2bFee($amount);
        }

        if ($operation === PaymentOperation::B2cDisbursement) {
            return $this->fixedB2cFee($amount);
        }

        return 0.0;
    }

    public function legacyC2bFee(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return round(min($amount * 0.75 / 100, $amount), 4);
    }

    public function fixedB2cFee(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return round(min(self::B2C_FIXED_FEE, $amount), 4);
    }

    public function isBeforeLegacyCutoff(CarbonInterface|string|null $asOf): bool
    {
        return Carbon::parse($asOf ?? now())->lt(Carbon::parse(self::LEGACY_CUTOFF)->startOfDay());
    }
}
