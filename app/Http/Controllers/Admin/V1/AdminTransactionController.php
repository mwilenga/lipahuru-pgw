<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminTransactionIndexRequest;
use App\Http\Resources\TransactionResource;
use App\Services\Payment\TransactionHistoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminTransactionController extends Controller
{
    public function __construct(
        private readonly TransactionHistoryService $transactionHistoryService,
    ) {}

    public function index(AdminTransactionIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->transactionHistoryService->listAll($filters);
        $summary = $this->transactionHistoryService->summarizeAll($filters);

        return ApiResponse::success([
            'transactions' => TransactionResource::collection($paginator->items()),
            'summary' => $summary,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }
}
