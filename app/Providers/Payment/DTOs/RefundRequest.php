<?php

namespace App\Providers\Payment\DTOs;

readonly class RefundRequest
{
    public function __construct(
        public string $refundId,
        public string $originalTransactionId,
        public string $providerReference,
        public string $amount,
        public string $currency,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}
}
