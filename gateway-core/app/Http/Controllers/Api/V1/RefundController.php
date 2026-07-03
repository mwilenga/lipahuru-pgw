<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Merchant;
use App\Services\Refund\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    public function store(RefundRequest $request, string $transactionId): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $payload = array_merge($request->validated(), ['transactionId' => $transactionId]);

        $refund = $this->refundService->createRefund($merchant, $payload);

        return ApiResponse::success(
            new RefundResource($refund->load('transaction')),
            requestId: (string) $request->header('X-Request-Id'),
        );
    }
}
