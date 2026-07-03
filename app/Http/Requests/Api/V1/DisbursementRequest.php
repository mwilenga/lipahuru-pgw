<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProviderCode;
use App\Http\Requests\GatewayFormRequest;
use Illuminate\Validation\Rule;

class DisbursementRequest extends GatewayFormRequest
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
            'requestId' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:64'],
            'providerCode' => ['required', 'string', Rule::in(ProviderCode::values())],
            'amount' => ['required', 'numeric', 'min:100', 'regex:/^\d+(\.\d{1,4})?$/'],
            'currency' => ['sometimes', 'string', 'size:3', 'in:TZS'],
            'msisdn' => ['required', 'string', 'regex:/^255[0-9]{9}$/'],
            'callbackUrl' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:255'],
            'externalReference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
