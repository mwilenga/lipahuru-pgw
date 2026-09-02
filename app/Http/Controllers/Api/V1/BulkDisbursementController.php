<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkDisbursementRequest;
use App\Http\Resources\DisbursementBatchResource;
use App\Models\Merchant;
use App\Services\Payment\BulkDisbursementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkDisbursementController extends Controller
{
    public function __construct(
        private readonly BulkDisbursementService $bulkDisbursementService,
    ) {}

    public function store(BulkDisbursementRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $batch = $this->bulkDisbursementService->create($merchant, $request->validated());

        return ApiResponse::success(
            new DisbursementBatchResource($batch),
            requestId: (string) $request->validated('requestId'),
        );
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $batch = $this->bulkDisbursementService->findForMerchantOrFail($batchId, $merchant);
        $batch = $this->bulkDisbursementService->refreshStatus($batch);

        return ApiResponse::success(new DisbursementBatchResource($batch));
    }
}
