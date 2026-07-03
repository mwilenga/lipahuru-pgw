<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TransactionHistoryRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Merchant;
use App\Services\Payment\TransactionHistoryService;
use App\Services\Payment\TransactionQueryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionQueryService $transactionQueryService,
        private readonly TransactionHistoryService $transactionHistoryService,
    ) {}

    public function show(Request $request, string $transactionId): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $transaction = $this->transactionQueryService->getByTransactionId($transactionId, $merchant->id);

        return ApiResponse::success(
            new TransactionResource($transaction),
            requestId: (string) $request->header('X-Request-Id'),
        );
    }

    public function index(TransactionHistoryRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $paginator = $this->transactionHistoryService->listForMerchant($merchant, $request->validated());

        return ApiResponse::success([
            'transactions' => TransactionResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ], requestId: (string) $request->header('X-Request-Id'));
    }
}
