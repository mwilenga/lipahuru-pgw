<?php

namespace App\Services\Auth;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Models\IdempotencyRecord;
use App\Repositories\Contracts\IdempotencyRepositoryInterface;

class IdempotencyService
{
    public function __construct(
        private readonly IdempotencyRepositoryInterface $idempotencyRepository,
    ) {}

    /**
     * @return array{http_status: int, response_body: array}|null
     */
    public function check(int $merchantId, string $idempotencyKey, string $requestHash): ?array
    {
        $record = $this->idempotencyRepository->findByKey($merchantId, $idempotencyKey);

        if ($record === null) {
            return null;
        }

        if ($record->expires_at !== null && $record->expires_at->isPast()) {
            return null;
        }

        if ($record->request_hash !== $requestHash) {
            throw new GatewayException(GatewayErrorCode::DuplicateRequest, httpStatus: 409);
        }

        return [
            'http_status' => $record->http_status,
            'response_body' => $record->response_body,
        ];
    }

    public function store(
        int $merchantId,
        string $idempotencyKey,
        string $requestHash,
        int $httpStatus,
        array $responseBody,
        ?int $ttlSeconds = null,
    ): IdempotencyRecord {
        $ttl = $ttlSeconds ?? (int) config('payment-gateway.token_ttl', 900) * 24;

        return $this->idempotencyRepository->store(
            merchantId: $merchantId,
            key: $idempotencyKey,
            requestHash: $requestHash,
            httpStatus: $httpStatus,
            responseBody: $responseBody,
            expiresAt: now()->addSeconds($ttl),
        );
    }

    public function hashRequest(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
