<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminFloatTopupRejectRequest;
use App\Http\Requests\Admin\V1\AdminFloatTopupStoreRequest;
use App\Http\Resources\FloatTopupResource;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Services\Wallet\FloatTopupService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFloatTopupController extends Controller
{
    public function __construct(
        private readonly FloatTopupService $floatTopupService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->floatTopupService->listForAdmin(
            status: $request->query('status'),
            merchantId: $request->query('merchantId') !== null
                ? (int) $request->query('merchantId')
                : null,
            perPage: (int) $request->query('perPage', 25),
        );

        return ApiResponse::success([
            'topups' => FloatTopupResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(AdminFloatTopupStoreRequest $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $merchant = Merchant::query()->findOrFail((int) $request->validated('merchantId'));

        $topup = $this->floatTopupService->createDirectByAdmin(
            merchant: $merchant,
            admin: $admin,
            items: $request->validated('items'),
            reference: $request->validated('reference'),
            notes: $request->validated('notes'),
        );

        return ApiResponse::success(
            new FloatTopupResource($topup),
            'Float topup credited successfully.',
        );
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $topup = $this->floatTopupService->findOrFail($id);
        $approved = $this->floatTopupService->approve($topup, $admin);

        return ApiResponse::success(
            new FloatTopupResource($approved),
            'Float topup approved successfully.',
        );
    }

    public function reject(AdminFloatTopupRejectRequest $request, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $topup = $this->floatTopupService->findOrFail($id);
        $rejected = $this->floatTopupService->reject(
            $topup,
            $admin,
            $request->validated('reason'),
        );

        return ApiResponse::success(
            new FloatTopupResource($rejected),
            'Float topup rejected.',
        );
    }
}
