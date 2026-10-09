<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Services\Report\MerchantFundsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMerchantFundsController extends Controller
{
    public function __construct(
        private readonly MerchantFundsService $merchantFundsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'providerCode' => ['nullable', 'string', 'max:32'],
        ]);

        return ApiResponse::success($this->merchantFundsService->summarize($filters));
    }
}
