<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'providerNetworkId' => $this->provider_network_id,
            'paymentProviderId' => $this->payment_provider_id,
            'paymentProvider' => new ProviderResource($this->whenLoaded('paymentProvider')),
            'operation' => $this->operation?->value,
            'priority' => $this->priority,
            'isActive' => $this->is_active,
            'isHealthy' => $this->is_healthy,
        ];
    }
}
