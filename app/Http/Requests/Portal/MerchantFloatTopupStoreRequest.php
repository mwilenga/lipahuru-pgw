<?php

namespace App\Http\Requests\Portal;

use App\Enums\ProviderCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantFloatTopupStoreRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.providerCode' => ['required', 'string', Rule::in(ProviderCode::values()), 'distinct'],
            'items.*.amount' => ['required', 'numeric', 'min:100', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
