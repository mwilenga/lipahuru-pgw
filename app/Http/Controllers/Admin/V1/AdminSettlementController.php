<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminSettlementTriggerRequest;
use App\Http\Resources\SettlementResource;
use App\Jobs\RunSettlementJob;
use App\Models\Settlement;
use App\Services\Settlement\SettlementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminSettlementController extends Controller
{
    public function __construct(
        private readonly SettlementService $settlementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = Settlement::query()
            ->withCount('items')
            ->latest('settlement_date')
            ->paginate((int) $request->query('perPage', 25));

        return ApiResponse::success([
            'settlements' => SettlementResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function trigger(AdminSettlementTriggerRequest $request): JsonResponse
    {
        $date = isset($request->validated()['settlement_date'])
            ? Carbon::parse($request->validated('settlement_date'))
            : now()->subDay()->startOfDay();

        if (isset($request->validated()['merchant_id'])) {
            $settlement = $this->settlementService->settleMerchant(
                (int) $request->validated('merchant_id'),
                $date,
            );

            return ApiResponse::success(
                $settlement ? new SettlementResource($settlement) : null,
                $settlement ? 'Settlement processed.' : 'No transactions to settle.',
            );
        }

        RunSettlementJob::dispatch($date);

        return ApiResponse::success(null, 'Settlement batch job dispatched.');
    }
}
