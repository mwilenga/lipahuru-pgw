<?php

namespace App\Http\Requests\Admin\V1;

use App\Enums\MerchantStatus;
use App\Http\Requests\GatewayFormRequest;
use Illuminate\Validation\Rule;

class AdminMerchantUpdateRequest extends GatewayFormRequest
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
        $merchantId = $this->route('merchant');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('merchants', 'email')->ignore($merchantId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(array_column(MerchantStatus::cases(), 'value'))],
            'environment' => ['sometimes', 'string', 'in:uat,production'],
            'default_currency' => ['sometimes', 'string', 'size:3', 'in:TZS'],
            'default_callback_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
