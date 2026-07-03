<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'fromStatus' => $this->from_status,
            'toStatus' => $this->to_status,
            'eventType' => $this->event_type,
            'actor' => $this->actor,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
