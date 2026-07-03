<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'walletId' => $this->id,
            'walletType' => $this->wallet_type?->value,
            'providerCode' => $this->providerNetwork?->code?->value,
            'name' => $this->name,
            'currency' => $this->currency,
            'isActive' => $this->is_active,
            'available' => (string) ($this->balance?->available ?? '0.0000'),
            'reserved' => (string) ($this->balance?->reserved ?? '0.0000'),
            'total' => (string) ($this->balance?->total ?? '0.0000'),
        ];
    }
}
