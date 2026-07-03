<?php

namespace App\Providers\Payment\DTOs;

use App\Enums\TransactionStatus;

readonly class ProviderWebhookEvent
{
    public function __construct(
        public string $providerTransactionId,
        public TransactionStatus $status,
        public string $eventType,
        public array $payload = [],
        public ?string $providerReceiptNo = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}
