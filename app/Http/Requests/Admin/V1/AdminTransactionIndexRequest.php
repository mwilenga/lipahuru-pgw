<?php

namespace App\Http\Requests\Admin\V1;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Http\Requests\GatewayFormRequest;
use Illuminate\Validation\Rule;

class AdminTransactionIndexRequest extends GatewayFormRequest
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
            'merchantId' => ['sometimes', 'nullable', 'integer', 'exists:merchants,id'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(TransactionStatus::cases(), 'value'))],
            'operation' => ['sometimes', 'nullable', 'string', Rule::in(array_column(PaymentOperation::cases(), 'value'))],
            'providerCode' => ['sometimes', 'nullable', 'string', 'max:32'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'search' => ['sometimes', 'nullable', 'string', 'max:128'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
