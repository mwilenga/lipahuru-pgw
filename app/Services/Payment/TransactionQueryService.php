<?php

namespace App\Services\Payment;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;

class TransactionQueryService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
    ) {}

    public function getByTransactionId(string $transactionId, ?int $merchantId = null): Transaction
    {
        $transaction = $this->transactionRepository->findByTransactionId($transactionId);

        if ($transaction === null) {
            throw new GatewayException(GatewayErrorCode::TransactionNotFound, httpStatus: 404);
        }

        if ($merchantId !== null && $transaction->merchant_id !== $merchantId) {
            throw new GatewayException(GatewayErrorCode::TransactionNotFound, httpStatus: 404);
        }

        return $transaction->load(['events', 'providerNetwork', 'paymentProvider']);
    }
}
