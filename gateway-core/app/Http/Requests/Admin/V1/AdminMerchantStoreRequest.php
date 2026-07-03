<?php

namespace App\Http\Requests\Admin\V1;

use App\Http\Requests\GatewayFormRequest;

class AdminMerchantStoreRequest extends GatewayFormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:merchants,email'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'environment' => ['sometimes', 'string', 'in:uat,production'],
            'default_currency' => ['sometimes', 'string', 'size:3', 'in:TZS'],
            'default_callback_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
