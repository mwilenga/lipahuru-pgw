<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transferId' => $this->transfer_id,
            'merchantId' => $this->merchant_id,
            'merchantName' => $this->merchant?->name,
            'fromWallet' => $this->walletPayload($this->fromWallet),
            'toWallet' => $this->walletPayload($this->toWallet),
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'source' => $this->source?->value,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'rejectionReason' => $this->rejection_reason,
            'reviewedBy' => $this->reviewer?->name,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function walletPayload(?Wallet $wallet): ?array
    {
        if ($wallet === null) {
            return null;
        }

        return [
            'walletId' => $wallet->id,
            'name' => $wallet->name,
            'walletType' => $wallet->wallet_type?->value,
            'providerCode' => $wallet->providerNetwork?->code?->value,
            'currency' => $wallet->currency,
        ];
    }
}
