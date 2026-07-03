<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'settlementId' => $this->settlement_id,
            'merchantId' => $this->merchant_id,
            'settlementDate' => $this->settlement_date?->toDateString(),
            'status' => $this->status,
            'grossAmount' => (string) $this->gross_amount,
            'feeAmount' => (string) $this->fee_amount,
            'netAmount' => (string) $this->net_amount,
            'currency' => $this->currency,
            'processedAt' => $this->processed_at?->toIso8601String(),
            'itemCount' => $this->whenCounted('items'),
        ];
    }
}
