<?php

namespace App\Http\Controllers\Admin\V1;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminMerchantCommissionUpdateRequest;
use App\Http\Resources\MerchantCommissionResource;
use App\Models\MerchantCommission;
use App\Services\Merchant\MerchantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminMerchantCommissionController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    public function index(int $merchant): JsonResponse
    {
        $model = $this->merchantService->findById($merchant);
        $commissions = MerchantCommission::query()
            ->where('merchant_id', $model->id)
            ->orderBy('operation')
            ->get();

        $defaults = collect([
            PaymentOperation::C2bPush,
            PaymentOperation::B2cDisbursement,
        ])->map(function (PaymentOperation $operation) use ($commissions) {
            $existing = $commissions->first(
                fn (MerchantCommission $row) => $row->operation === $operation,
            );

            if ($existing !== null) {
                return $existing;
            }

            return new MerchantCommission([
                'operation' => $operation,
                'commission_type' => CommissionType::Percent,
                'value' => '0.0000',
            ]);
        });

        return ApiResponse::success([
            'commissions' => MerchantCommissionResource::collection($defaults),
        ]);
    }

    public function update(AdminMerchantCommissionUpdateRequest $request, int $merchant): JsonResponse
    {
        $model = $this->merchantService->findById($merchant);
        $payload = $request->validated('commissions');

        $saved = DB::transaction(function () use ($model, $payload) {
            $rows = [];

            foreach ($payload as $item) {
                $rows[] = MerchantCommission::query()->updateOrCreate(
                    [
                        'merchant_id' => $model->id,
                        'operation' => $item['operation'],
                    ],
                    [
                        'commission_type' => $item['commissionType'],
                        'value' => $item['value'],
                    ],
                );
            }

            return collect($rows);
        });

        return ApiResponse::success([
            'commissions' => MerchantCommissionResource::collection($saved),
        ], 'Commission settings saved.');
    }
}
