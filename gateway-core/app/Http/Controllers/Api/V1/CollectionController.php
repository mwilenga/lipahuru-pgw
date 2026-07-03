<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CollectionPushRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Merchant;
use App\Services\Payment\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CollectionController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function push(CollectionPushRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $transaction = $this->paymentService->createCollection($merchant, $request->validated());

        return ApiResponse::success(
            new TransactionResource($transaction),
            requestId: (string) $request->header('X-Request-Id'),
        );
    }
}
