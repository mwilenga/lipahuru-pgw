<?php

namespace App\Repositories\Eloquent;

use App\Models\IdempotencyRecord;
use App\Repositories\Contracts\IdempotencyRepositoryInterface;
use DateTimeInterface;

class EloquentIdempotencyRepository implements IdempotencyRepositoryInterface
{
    public function findByKey(int $merchantId, string $key): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('merchant_id', $merchantId)
            ->where('idempotency_key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function store(
        int $merchantId,
        string $key,
        string $requestHash,
        int $httpStatus,
        array $responseBody,
        ?DateTimeInterface $expiresAt = null,
    ): IdempotencyRecord {
        return IdempotencyRecord::query()->updateOrCreate(
            [
                'merchant_id' => $merchantId,
                'idempotency_key' => $key,
            ],
            [
                'request_hash' => $requestHash,
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
                'expires_at' => $expiresAt,
            ],
        );
    }
}
