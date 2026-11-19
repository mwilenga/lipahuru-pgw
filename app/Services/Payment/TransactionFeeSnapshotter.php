<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use App\Services\Merchant\CommissionFeeCalculator;
use App\Services\Provider\ProviderFeeCalculator;

class TransactionFeeSnapshotter
{
    public function __construct(
        private readonly CommissionFeeCalculator $commissionFeeCalculator,
        private readonly ProviderFeeCalculator $providerFeeCalculator,
    ) {}

    /**
     * Persist merchant and provider fees on a successful transaction (idempotent).
     */
    public function snapshot(Transaction $transaction): Transaction
    {
        $transaction->loadMissing('merchant');

        $updates = [];

        if ($transaction->merchant_fee_amount === null) {
            $merchantFees = $this->commissionFeeCalculator->forTransaction($transaction);
            $updates['merchant_fee_amount'] = $merchantFees['fee'];
        }

        if ($transaction->provider_fee_amount === null) {
            $providerFees = $this->providerFeeCalculator->forTransaction($transaction);
            $updates['provider_fee_amount'] = $providerFees['fee'];
            $updates['provider_fee_rate_id'] = $providerFees['rate']?->id;
        }

        if ($updates !== []) {
            $transaction->update($updates);
        }

        return $transaction->refresh();
    }
}
