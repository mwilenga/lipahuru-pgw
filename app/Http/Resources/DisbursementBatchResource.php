<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisbursementBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'batchId' => $this->batch_id,
            'batchReference' => $this->batch_reference,
            'status' => $this->status?->value,
            'currency' => $this->currency,
            'totalAmount' => (string) $this->total_amount,
            'totalItems' => $this->total_items,
            'pendingCount' => $this->pending_count,
            'successCount' => $this->success_count,
            'failedCount' => $this->failed_count,
            'callbackUrl' => $this->callback_url,
            'items' => TransactionResource::collection($this->whenLoaded('transactions')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
