<?php

namespace App\Http\Requests;

use App\Enums\GatewayErrorCode;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class GatewayFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::failed(
                GatewayErrorCode::InvalidPayload,
                $validator->errors()->first(),
                (string) $this->header('X-Request-Id'),
                ['errors' => $validator->errors()],
            )
        );
    }
}
