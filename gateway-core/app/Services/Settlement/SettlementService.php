<?php

namespace App\Services\Settlement;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\SettlementItem;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementService
{
    /**
     * @return Collection<int, Settlement>
     */
    public function batchSettle(?\DateTimeInterface $settlementDate = null): Collection
    {
        $date = $settlementDate ?? now()->subDay()->startOfDay();

        $merchants = Merchant::query()
            ->where('status', 'ACTIVE')
            ->pluck('id');

        $settlements = collect();

        foreach ($merchants as $merchantId) {
            $settlement = $this->settleMerchant((int) $merchantId, $date);

            if ($settlement !== null) {
                $settlements->push($settlement);
            }
        }

        return $settlements;
    }

    public function settleMerchant(int $merchantId, \DateTimeInterface $settlementDate): ?Settlement
    {
        return DB::transaction(function () use ($merchantId, $settlementDate): ?Settlement {
            $transactions = Transaction::query()
                ->where('merchant_id', $merchantId)
                ->where('status', TransactionStatus::Success)
                ->whereDate('finalized_at', $settlementDate)
                ->whereDoesntHave('settlementItems')
                ->lockForUpdate()
                ->get();

            if ($transactions->isEmpty()) {
                return null;
            }

            $grossAmount = $transactions->reduce(
                fn (string $carry, Transaction $transaction) => bcadd($carry, (string) $transaction->amount, 4),
                '0.0000',
            );

            $feeAmount = '0.0000';
            $netAmount = bcsub($grossAmount, $feeAmount, 4);
            $currency = $transactions->first()->currency ?? config('payment-gateway.default_currency', 'TZS');

            $settlement = Settlement::query()->create([
                'settlement_id' => $this->generateSettlementId(),
                'merchant_id' => $merchantId,
                'settlement_date' => $settlementDate,
                'status' => 'PROCESSED',
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'currency' => $currency,
                'processed_at' => now(),
            ]);

            foreach ($transactions as $transaction) {
                SettlementItem::query()->create([
                    'settlement_id' => $settlement->id,
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'fee_amount' => 0,
                ]);
            }

            return $settlement->load('items');
        });
    }

    private function generateSettlementId(): string
    {
        return 'STL-'.strtoupper(Str::random(16));
    }
}
