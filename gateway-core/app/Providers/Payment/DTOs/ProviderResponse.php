<?php

namespace App\Providers\Payment\DTOs;

use App\Enums\TransactionStatus;

readonly class ProviderResponse
{
    public function __construct(
        public bool $success,
        public TransactionStatus $status,
        public ?string $providerTransactionId = null,
        public ?string $providerReceiptNo = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $rawResponse = [],
    ) {}
}
