<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DisbursementRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Merchant;
use App\Services\Payment\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DisbursementController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function store(DisbursementRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $transaction = $this->paymentService->createDisbursement($merchant, $request->validated());

        return ApiResponse::success(
            new TransactionResource($transaction),
            requestId: (string) $request->header('X-Request-Id'),
        );
    }
}
