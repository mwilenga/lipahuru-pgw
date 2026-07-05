<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminMerchantStoreRequest;
use App\Http\Requests\Admin\V1\AdminMerchantUpdateRequest;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\Merchant\MerchantOnboardingService;
use App\Services\Merchant\MerchantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        $data = $request->validated();
        $ownerPassword = $data['owner_password'] ?? Str::password(12);
        unset($data['owner_password']);

        $result = $this->merchantOnboardingService->onboard($data);

        MerchantUser::query()->create([
            'merchant_id' => $result['merchant']->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $ownerPassword,
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return ApiResponse::success([
            'merchant' => new MerchantResource($result['merchant']),
            'clientId' => $result['client_id'],
            'clientSecret' => $result['client_secret'],
            'portalEmail' => $data['email'],
            'portalPassword' => $ownerPassword,
        ], 'Merchant created successfully. Store API and portal credentials securely — shown once.');
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
