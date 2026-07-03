<?php

namespace App\Http\Controllers\Api\V1;

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
            requestId: (string) $request->header('X-Request-Id'),
        );
    }

    public function show(Request $request, string $providerCode): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $wallet = $this->walletQueryService->getByProviderCode($merchant, $providerCode);

        return ApiResponse::success(
            new WalletResource($wallet),
            requestId: (string) $request->header('X-Request-Id'),
        );
    }
}
