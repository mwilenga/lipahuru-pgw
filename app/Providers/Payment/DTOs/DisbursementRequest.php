<?php

namespace App\Providers\Payment\DTOs;

readonly class DisbursementRequest
{
    public function __construct(
        public string $transactionId,
        public string $reference,
        public string $amount,
        public string $currency,
        public string $msisdn,
        public string $providerCode,
        public ?string $narration = null,
        public ?string $callbackUrl = null,
        public array $metadata = [],
    ) {}
}
