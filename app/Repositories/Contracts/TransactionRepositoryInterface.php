<?php

namespace App\Repositories\Contracts;

use App\Enums\TransactionStatus;
use App\Models\Transaction;

interface TransactionRepositoryInterface
{
    public function findById(int $id): ?Transaction;

    public function findByTransactionId(string $transactionId): ?Transaction;

    public function findByMerchantAndReference(int $merchantId, string $reference): ?Transaction;

    public function findByMerchantAndRequestId(int $merchantId, string $requestId): ?Transaction;

    public function findByProviderReference(string $providerTransactionId): ?Transaction;

    public function findByReference(string $reference): ?Transaction;

    public function findByRequestId(string $requestId): ?Transaction;

    public function create(array $attributes): Transaction;

    public function updateStatus(Transaction $transaction, TransactionStatus $status): Transaction;
}
