<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminProviderFeeRateStoreRequest;
use App\Http\Resources\ProviderFeeRateResource;
use App\Models\ProviderFeeRate;
use App\Services\Provider\ProviderFeeRateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProviderFeeRateController extends Controller
{
    public function __construct(
        private readonly ProviderFeeRateService $providerFeeRateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $activeOnly = $request->query('activeOnly');
        $filter = null;

        if ($activeOnly === '1' || $activeOnly === 'true') {
            $filter = true;
        } elseif ($activeOnly === '0' || $activeOnly === 'false') {
            $filter = false;
        }

        return ApiResponse::success([
            'rates' => ProviderFeeRateResource::collection(
                $this->providerFeeRateService->list($filter),
            ),
        ]);
    }

    public function store(AdminProviderFeeRateStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $rate = $this->providerFeeRateService->create($payload);
        $active = array_key_exists('isActive', $payload) ? (bool) $payload['isActive'] : true;

        return ApiResponse::success(
            new ProviderFeeRateResource($rate),
            $active
                ? 'Provider fee rate added. Any previous active rate for this network and operation was deactivated.'
                : 'Provider fee rate added as inactive.',
        );
    }

    public function update(AdminProviderFeeRateStoreRequest $request, int $id): JsonResponse
    {
        $rate = ProviderFeeRate::query()->findOrFail($id);
        $payload = $request->validated();
        $updated = $this->providerFeeRateService->update($rate, $payload);
        $active = array_key_exists('isActive', $payload)
            ? (bool) $payload['isActive']
            : (bool) $updated->is_active;

        return ApiResponse::success(
            new ProviderFeeRateResource($updated),
            $active
                ? 'Provider fee rate updated. Any other active rate for this network and operation was deactivated.'
                : 'Provider fee rate updated.',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $rate = ProviderFeeRate::query()->findOrFail($id);
        $this->providerFeeRateService->delete($rate);

        return ApiResponse::success(null, 'Provider fee rate deleted.');
    }
}
