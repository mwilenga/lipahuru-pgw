<?php

namespace App\Http\Requests\Admin\V1;

use App\Http\Requests\GatewayFormRequest;

class AdminSettlementTriggerRequest extends GatewayFormRequest
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
            'merchant_id' => ['sometimes', 'integer', 'exists:merchants,id'],
            'settlement_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
