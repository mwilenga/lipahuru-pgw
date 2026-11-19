<?php

namespace App\Http\Resources;

use App\Services\Merchant\CommissionFeeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calculated = app(CommissionFeeCalculator::class)->forTransaction($this->resource);

        $merchantFee = $this->merchant_fee_amount !== null
            ? number_format((float) $this->merchant_fee_amount, 4, '.', '')
            : $calculated['fee'];

        $amount = number_format((float) $this->amount, 4, '.', '');
        $net = bcsub($amount, $merchantFee, 4);
        if (bccomp($net, '0', 4) < 0) {
            $net = '0.0000';
        }

        $isAdmin = $request->is('api/admin/*');
        $providerFee = $this->provider_fee_amount !== null
            ? number_format((float) $this->provider_fee_amount, 4, '.', '')
            : null;

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
            'fee' => $merchantFee,
            'net' => $net,
            'providerFee' => $this->when($isAdmin, $providerFee),
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
