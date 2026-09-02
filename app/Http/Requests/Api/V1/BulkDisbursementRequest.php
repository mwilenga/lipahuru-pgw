<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProviderCode;
use App\Http\Requests\GatewayFormRequest;
use Illuminate\Validation\Rule;

class BulkDisbursementRequest extends GatewayFormRequest
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
        $maxItems = (int) config('payment-gateway.bulk_disbursement_max_items', 500);

        return [
            'requestId' => ['required', 'uuid'],
            'batchReference' => ['required', 'string', 'max:64'],
            'providerCode' => ['sometimes', 'string', Rule::in(ProviderCode::values())],
            'currency' => ['sometimes', 'string', 'size:3', 'in:TZS'],
            'callbackUrl' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'items' => ['required', 'array', 'min:1', 'max:'.$maxItems],
            'items.*.requestId' => ['required', 'uuid'],
            'items.*.reference' => ['required', 'string', 'max:64'],
            'items.*.msisdn' => ['required', 'string', 'regex:/^255[0-9]{9}$/'],
            'items.*.amount' => ['required', 'numeric', 'min:100', 'regex:/^\d+(\.\d{1,4})?$/'],
            'items.*.providerCode' => ['required', 'string', Rule::in(ProviderCode::values())],
            'items.*.narration' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.externalReference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'items.*.metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
