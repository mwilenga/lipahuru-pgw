<?php

namespace App\Providers\Payment\DTOs;

use App\Enums\TransactionStatus;

readonly class ProviderStatusResponse
{
    public function __construct(
        public string $providerReference,
        public TransactionStatus $status,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $providerReceiptNo = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $rawResponse = [],
    ) {}
}
