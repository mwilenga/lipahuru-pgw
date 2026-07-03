<?php

namespace App\Services\Refund;

use App\Enums\GatewayErrorCode;
use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Models\Refund;
use App\Models\Transaction;
use App\Providers\Payment\DTOs\RefundRequest;
use App\Providers\Payment\ProviderRouter;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ProviderRouter $providerRouter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRefund(Merchant $merchant, array $payload): Refund
    {
        $transaction = $this->transactionRepository->findByTransactionId((string) $payload['transactionId']);

        if ($transaction === null || $transaction->merchant_id !== $merchant->id) {
            throw new GatewayException(GatewayErrorCode::TransactionNotFound, httpStatus: 404);
        }

        if ($transaction->status !== TransactionStatus::Success) {
            throw new GatewayException(GatewayErrorCode::InvalidPayload, 'Only successful transactions can be refunded', httpStatus: 422);
        }

        $amount = (string) ($payload['amount'] ?? $transaction->amount);

        if (bccomp($amount, (string) $transaction->amount, 4) > 0) {
            throw new GatewayException(GatewayErrorCode::AmountLimitExceeded, 'Refund amount exceeds transaction amount', httpStatus: 422);
        }

        return DB::transaction(function () use ($merchant, $transaction, $payload, $amount): Refund {
            $refund = Refund::query()->create([
                'refund_id' => $this->generateRefundId(),
                'transaction_id' => $transaction->id,
                'merchant_id' => $merchant->id,
                'request_id' => $payload['requestId'],
                'amount' => $amount,
                'currency' => $transaction->currency,
                'status' => 'PENDING',
                'reason' => $payload['reason'] ?? null,
            ]);

            $providerCode = $transaction->providerNetwork?->code?->value;

            if ($providerCode === null) {
                return $refund;
            }

            $provider = $this->providerRouter->resolve($providerCode, PaymentOperation::Refund);

            $response = $provider->initiateRefund(new RefundRequest(
                refundId: $refund->refund_id,
                originalTransactionId: $transaction->transaction_id,
                providerReference: (string) $transaction->provider_transaction_id,
                amount: $amount,
                currency: $transaction->currency,
                reason: $refund->reason,
                metadata: $payload['metadata'] ?? [],
            ));

            $refund->update([
                'provider_refund_id' => $response->providerTransactionId,
                'status' => $response->success ? 'SUCCESS' : 'FAILED',
                'finalized_at' => now(),
            ]);

            return $refund->refresh();
        });
    }

    private function generateRefundId(): string
    {
        return 'RFD-'.strtoupper(Str::random(16));
    }
}
