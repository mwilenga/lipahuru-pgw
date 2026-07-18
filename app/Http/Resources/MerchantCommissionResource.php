<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantCommissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'operation' => $this->operation?->value,
            'commissionType' => $this->commission_type?->value,
            'value' => (string) $this->value,
        ];
    }
}
