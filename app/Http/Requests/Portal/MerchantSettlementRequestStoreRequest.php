<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class MerchantSettlementRequestStoreRequest extends FormRequest
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
            'walletId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:100', 'regex:/^\d+(\.\d{1,4})?$/'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
