<?php

namespace App\Http\Requests\Admin\V1;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Http\Requests\GatewayFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminProviderFeeRateStoreRequest extends GatewayFormRequest
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
            'providerNetworkId' => ['required', 'integer', 'exists:provider_networks,id'],
            'operation' => [
                'required',
                'string',
                Rule::in([
                    PaymentOperation::C2bPush->value,
                    PaymentOperation::B2cDisbursement->value,
                ]),
            ],
            'commissionType' => [
                'required',
                'string',
                Rule::in(array_column(CommissionType::cases(), 'value')),
            ],
            'value' => ['required', 'numeric', 'min:0'],
            'effectiveFrom' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('commissionType') !== CommissionType::Percent->value) {
                return;
            }

            if ((float) $this->input('value', 0) > 100) {
                $validator->errors()->add(
                    'value',
                    'Percent provider fee value may not be greater than 100.',
                );
            }
        });
    }
}
