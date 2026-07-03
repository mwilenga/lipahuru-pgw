<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'legalName' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'registrationNumber' => $this->registration_number,
            'taxId' => $this->tax_id,
            'status' => $this->status?->value,
            'environment' => $this->environment,
            'defaultCurrency' => $this->default_currency,
            'defaultCallbackUrl' => $this->default_callback_url,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
