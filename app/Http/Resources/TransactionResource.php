<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transactionId' => $this->transaction_id,
            'requestId' => $this->request_id,
            'reference' => $this->reference,
            'externalReference' => $this->external_reference,
            'operation' => $this->operation?->value,
            'status' => $this->status?->value,
            'transactionStatus' => $this->status?->value,
            'providerCode' => $this->providerNetwork?->code?->value,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'msisdn' => $this->msisdn,
            'callbackUrl' => $this->callback_url,
            'narration' => $this->narration,
            'providerTransactionId' => $this->provider_transaction_id,
            'providerReceiptNo' => $this->provider_receipt_no,
            'failureCode' => $this->failure_code,
            'failureMessage' => $this->failure_message,
            'merchantName' => $this->merchant?->name,
            'finalizedAt' => $this->finalized_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'events' => TransactionEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
