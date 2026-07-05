<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TransactionHistoryRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Models\Merchant;
use App\Services\Payment\TransactionHistoryService;
use App\Services\Portal\MerchantPortalService;
use App\Services\Wallet\WalletQueryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantPortalController extends Controller
{
    public function __construct(
        private readonly MerchantPortalService $merchantPortalService,
        private readonly WalletQueryService $walletQueryService,
        private readonly TransactionHistoryService $transactionHistoryService,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        return ApiResponse::success(
            $this->merchantPortalService->dashboard($merchant),
        );
    }

    public function wallets(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        return ApiResponse::success(
            WalletResource::collection($this->walletQueryService->listForMerchant($merchant)),
        );
    }

    public function transactions(TransactionHistoryRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $paginator = $this->transactionHistoryService->listForMerchant($merchant, $request->validated());

        return ApiResponse::success([
            'transactions' => TransactionResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('merchant_user');
        $merchant = $request->attributes->get('merchant');

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'merchant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'email' => $merchant->email,
                'status' => $merchant->status?->value,
                'defaultCurrency' => $merchant->default_currency,
            ],
        ]);
    }
}
