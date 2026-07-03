<?php

namespace App\Repositories\Contracts;

use App\Models\IdempotencyRecord;
use DateTimeInterface;

interface IdempotencyRepositoryInterface
{
    public function findByKey(int $merchantId, string $key): ?IdempotencyRecord;

    public function store(
        int $merchantId,
        string $key,
        string $requestHash,
        int $httpStatus,
        array $responseBody,
        ?DateTimeInterface $expiresAt = null,
    ): IdempotencyRecord;
}
