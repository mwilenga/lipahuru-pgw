<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GatewayErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Models\Merchant;
use App\Services\Wallet\WalletQueryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletQueryService $walletQueryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $wallets = $this->walletQueryService->listForMerchant($merchant);

        return ApiResponse::success(
            WalletResource::collection($wallets),
        );
    }

    public function show(Request $request, string $providerCode): JsonResponse
    {
        return ApiResponse::failed(
            GatewayErrorCode::GeneralError,
            'Provider-specific wallets are retired. Use GET /api/v1/wallets for the single merchant balance. providerCode selects the payment channel on collection/disbursement only.',
            httpStatus: 410,
        );
    }
}
