<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\GatewayFormRequest;

class OAuthTokenRequest extends GatewayFormRequest
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
            'grant_type' => ['required', 'string', 'in:client_credentials'],
            'client_id' => ['required', 'string', 'max:64'],
            'client_secret' => ['required', 'string', 'max:128'],
        ];
    }
}
