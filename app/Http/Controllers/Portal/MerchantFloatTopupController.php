<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\MerchantFloatTopupStoreRequest;
use App\Http\Resources\FloatTopupResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\Wallet\FloatTopupService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantFloatTopupController extends Controller
{
    public function __construct(
        private readonly FloatTopupService $floatTopupService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $paginator = $this->floatTopupService->listForMerchant(
            $merchant,
            (int) $request->query('perPage', 25),
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

    public function store(MerchantFloatTopupStoreRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        /** @var MerchantUser $user */
        $user = $request->attributes->get('merchant_user');

        $topup = $this->floatTopupService->requestByMerchant(
            merchant: $merchant,
            user: $user,
            items: $request->validated('items'),
            reference: $request->validated('reference'),
            notes: $request->validated('notes'),
        );

        return ApiResponse::success(
            new FloatTopupResource($topup),
            'Float topup request submitted successfully.',
        );
    }
}
