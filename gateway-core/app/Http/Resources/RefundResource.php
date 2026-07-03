<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'refundId' => $this->refund_id,
            'transactionId' => $this->transaction?->transaction_id,
            'requestId' => $this->request_id,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'reason' => $this->reason,
            'providerRefundId' => $this->provider_refund_id,
            'finalizedAt' => $this->finalized_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
