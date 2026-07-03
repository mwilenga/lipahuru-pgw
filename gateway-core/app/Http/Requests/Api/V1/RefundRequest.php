<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\GatewayFormRequest;

class RefundRequest extends GatewayFormRequest
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
            'amount' => ['sometimes', 'numeric', 'min:100', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'transactionId' => $this->route('transactionId'),
        ]);
    }
}
