<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminSettlementRequestRejectRequest;
use App\Http\Resources\SettlementRequestResource;
use App\Models\AdminUser;
use App\Services\Settlement\SettlementRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettlementRequestController extends Controller
{
    public function __construct(
        private readonly SettlementRequestService $settlementRequestService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->settlementRequestService->listForAdmin(
            filters: $request->only(['status', 'search', 'from', 'to', 'merchantId']),
            perPage: (int) $request->query('perPage', 25),
        );

        return ApiResponse::success([
            'settlements' => SettlementRequestResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $settlement = $this->settlementRequestService->findOrFail($id);

        return ApiResponse::success(
            new SettlementRequestResource($this->settlementRequestService->approve($settlement, $admin)),
            'Settlement approved. Pay the merchant bank account to complete it.',
        );
    }

    public function reject(AdminSettlementRequestRejectRequest $request, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $settlement = $this->settlementRequestService->findOrFail($id);

        return ApiResponse::success(
            new SettlementRequestResource($this->settlementRequestService->reject(
                $settlement,
                $admin,
                $request->validated('reason'),
            )),
            'Settlement request rejected.',
        );
    }
}
