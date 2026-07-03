<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderNetworkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code?->value ?? $this->code,
            'name' => $this->name,
            'isActive' => $this->is_active,
            'routes' => ProviderRouteResource::collection($this->whenLoaded('routes')),
        ];
    }
}
