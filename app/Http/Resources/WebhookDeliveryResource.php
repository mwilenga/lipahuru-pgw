<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'callbackId' => $this->callback_id,
            'merchantId' => $this->merchant_id,
            'transactionId' => $this->transaction?->transaction_id,
            'eventType' => $this->event_type,
            'url' => $this->url,
            'attempt' => $this->attempt,
            'maxAttempts' => $this->max_attempts,
            'status' => $this->status,
            'httpStatus' => $this->http_status,
            'nextRetryAt' => $this->next_retry_at?->toIso8601String(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
