<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminMerchantStoreRequest;
use App\Http\Requests\Admin\V1\AdminMerchantUpdateRequest;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Services\Merchant\MerchantOnboardingService;
use App\Services\Merchant\MerchantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMerchantController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
        private readonly MerchantOnboardingService $merchantOnboardingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $merchants = $this->merchantService->list((int) $request->query('perPage', 25));

        return ApiResponse::success([
            'merchants' => MerchantResource::collection($merchants->items()),
            'pagination' => [
                'currentPage' => $merchants->currentPage(),
                'perPage' => $merchants->perPage(),
                'total' => $merchants->total(),
                'lastPage' => $merchants->lastPage(),
            ],
        ]);
    }

    public function store(AdminMerchantStoreRequest $request): JsonResponse
    {
        $result = $this->merchantOnboardingService->onboard($request->validated());

        return ApiResponse::success([
            'merchant' => new MerchantResource($result['merchant']),
            'clientId' => $result['client_id'],
            'clientSecret' => $result['client_secret'],
            'signingSecret' => $result['signing_secret'],
            'callbackSecret' => $result['callback_secret'],
        ], 'Merchant created successfully.');
    }

    public function show(int $merchant): JsonResponse
    {
        return ApiResponse::success(new MerchantResource($this->merchantService->findById($merchant)));
    }

    public function update(AdminMerchantUpdateRequest $request, int $merchant): JsonResponse
    {
        $model = $this->merchantService->findById($merchant);
        $updated = $this->merchantService->update($model, $request->validated());

        return ApiResponse::success(new MerchantResource($updated), 'Merchant updated successfully.');
    }

    public function destroy(int $merchant): JsonResponse
    {
        $model = $this->merchantService->findById($merchant);
        $model->delete();

        return ApiResponse::success(null, 'Merchant deleted successfully.');
    }

    public function approve(int $merchant): JsonResponse
    {
        $model = $this->merchantService->findById($merchant);
        $approved = $this->merchantService->approve($model);

        return ApiResponse::success(new MerchantResource($approved), 'Merchant approved successfully.');
    }

    public function suspend(Request $request, int $merchant): JsonResponse
    {
        $model = $this->merchantService->findById($merchant);
        $suspended = $this->merchantService->suspend($model, $request->input('reason'));

        return ApiResponse::success(new MerchantResource($suspended), 'Merchant suspended successfully.');
    }
}
