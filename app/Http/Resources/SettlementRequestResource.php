<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requestId' => $this->request_id,
            'merchantId' => $this->merchant_id,
            'merchantName' => $this->merchant?->name,
            'wallet' => $this->wallet === null ? null : [
                'walletId' => $this->wallet->id,
                'name' => $this->wallet->name,
                'providerCode' => $this->wallet->providerNetwork?->code?->value,
                'currency' => $this->wallet->currency,
            ],
            'amount' => (string) $this->amount,
            'commissionAmount' => (string) $this->commission_amount,
            'totalDebit' => (string) $this->total_debit,
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'memo' => $this->memo,
            'rejectionReason' => $this->rejection_reason,
            'bankName' => $this->bank_name,
            'bankAccountName' => $this->bank_account_name,
            'bankAccountNumber' => $this->bank_account_number,
            'bankBranch' => $this->bank_branch,
            'requestedBy' => $this->requester?->name,
            'reviewedBy' => $this->reviewer?->name,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
