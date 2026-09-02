<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminBalanceCreditStoreRequest;
use App\Http\Resources\BalanceCreditResource;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Services\Wallet\BalanceCreditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBalanceCreditController extends Controller
{
    public function __construct(
        private readonly BalanceCreditService $balanceCreditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->balanceCreditService->listForAdmin(
            merchantId: $request->query('merchantId') !== null
                ? (int) $request->query('merchantId')
                : null,
            perPage: (int) $request->query('perPage', 25),
        );

        return ApiResponse::success([
            'credits' => BalanceCreditResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(AdminBalanceCreditStoreRequest $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $merchant = Merchant::query()->findOrFail((int) $request->validated('merchantId'));

        $credit = $this->balanceCreditService->create(
            merchant: $merchant,
            admin: $admin,
            amount: (string) $request->validated('amount'),
            reference: $request->validated('reference'),
            notes: $request->validated('notes'),
        );

        return ApiResponse::success(
            new BalanceCreditResource($credit),
            'Balance credited successfully.',
        );
    }
}
