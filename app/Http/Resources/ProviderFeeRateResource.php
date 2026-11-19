<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderFeeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'providerNetworkId' => $this->provider_network_id,
            'providerCode' => $this->providerNetwork?->code?->value,
            'providerName' => $this->providerNetwork?->name,
            'operation' => $this->operation?->value,
            'commissionType' => $this->commission_type?->value,
            'value' => (string) $this->value,
            'isActive' => (bool) $this->is_active,
            'effectiveFrom' => $this->effective_from?->toIso8601String(),
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
