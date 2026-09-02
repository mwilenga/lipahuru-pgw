<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceCreditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'creditId' => $this->credit_id,
            'merchantId' => $this->merchant_id,
            'merchantName' => $this->merchant?->name,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'createdBy' => $this->creator?->name,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
