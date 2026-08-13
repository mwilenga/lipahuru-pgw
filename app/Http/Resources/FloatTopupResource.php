<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloatTopupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topupId' => $this->topup_id,
            'merchantId' => $this->merchant_id,
            'merchantName' => $this->merchant?->name,
            'source' => $this->source?->value,
            'status' => $this->status?->value,
            'currency' => $this->currency,
            'totalAmount' => (string) $this->total_amount,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'rejectionReason' => $this->rejection_reason,
            'items' => $this->items->map(static fn ($item) => [
                'providerCode' => $item->providerNetwork?->code?->value,
                'providerName' => $item->providerNetwork?->name,
                'walletId' => $item->wallet_id,
                'amount' => (string) $item->amount,
            ])->values()->all(),
            'reviewedBy' => $this->reviewer?->name,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
