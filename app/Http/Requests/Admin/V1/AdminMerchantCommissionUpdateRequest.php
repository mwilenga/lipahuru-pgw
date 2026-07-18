<?php

namespace App\Http\Requests\Admin\V1;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Http\Requests\GatewayFormRequest;
use Illuminate\Validation\Rule;

class AdminMerchantCommissionUpdateRequest extends GatewayFormRequest
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
            'commissions' => ['required', 'array', 'min:1'],
            'commissions.*.operation' => [
                'required',
                'string',
                Rule::in([
                    PaymentOperation::C2bPush->value,
                    PaymentOperation::B2cDisbursement->value,
                ]),
            ],
            'commissions.*.commissionType' => [
                'required',
                'string',
                Rule::in(array_column(CommissionType::cases(), 'value')),
            ],
            'commissions.*.value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
