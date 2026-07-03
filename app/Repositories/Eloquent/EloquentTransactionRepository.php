<?php

namespace App\Repositories\Eloquent;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;

class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    public function findById(int $id): ?Transaction
    {
        return Transaction::query()->find($id);
    }

    public function findByTransactionId(string $transactionId): ?Transaction
    {
        return Transaction::query()
            ->where('transaction_id', $transactionId)
            ->first();
    }

    public function findByMerchantAndReference(int $merchantId, string $reference): ?Transaction
    {
        return Transaction::query()
            ->where('merchant_id', $merchantId)
            ->where('reference', $reference)
            ->first();
    }

    public function findByMerchantAndRequestId(int $merchantId, string $requestId): ?Transaction
    {
        return Transaction::query()
            ->where('merchant_id', $merchantId)
            ->where('request_id', $requestId)
            ->first();
    }

    public function findByProviderReference(string $providerTransactionId): ?Transaction
    {
        return Transaction::query()
            ->where('provider_transaction_id', $providerTransactionId)
            ->first();
    }

    public function create(array $attributes): Transaction
    {
        return Transaction::query()->create($attributes);
    }

    public function updateStatus(Transaction $transaction, TransactionStatus $status): Transaction
    {
        $transaction->update(['status' => $status]);

        return $transaction->refresh();
    }
}
