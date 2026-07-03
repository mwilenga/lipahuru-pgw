<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\GatewayFormRequest;

class WebhookRegisterRequest extends GatewayFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
