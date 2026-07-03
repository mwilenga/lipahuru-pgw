<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actorType' => $this->actor_type,
            'actorId' => $this->actor_id,
            'action' => $this->action,
            'resourceType' => $this->resource_type,
            'resourceId' => $this->resource_id,
            'ipAddress' => $this->ip_address,
            'before' => $this->before,
            'after' => $this->after,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
