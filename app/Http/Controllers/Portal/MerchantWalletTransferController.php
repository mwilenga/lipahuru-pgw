<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\MerchantWalletTransferStoreRequest;
use App\Http\Resources\WalletTransferResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\Wallet\WalletTransferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantWalletTransferController extends Controller
{
    public function __construct(
        private readonly WalletTransferService $walletTransferService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $paginator = $this->walletTransferService->listForMerchant(
            merchant: $merchant,
            filters: $request->only(['status', 'search', 'from', 'to']),
            perPage: (int) $request->query('perPage', 25),
        );

        return ApiResponse::success([
            'transfers' => WalletTransferResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(MerchantWalletTransferStoreRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        /** @var MerchantUser $user */
        $user = $request->attributes->get('merchant_user');

        $transfer = $this->walletTransferService->requestByMerchant(
            merchant: $merchant,
            user: $user,
            fromWalletId: (int) $request->validated('fromWalletId'),
            toWalletId: (int) $request->validated('toWalletId'),
            amount: (string) $request->validated('amount'),
            reference: $request->validated('reference'),
            notes: $request->validated('notes'),
        );

        return ApiResponse::success(
            new WalletTransferResource($transfer),
            'Transfer request submitted successfully.',
        );
    }

    public function transferableWallets(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $wallets = $this->walletTransferService->transferableWallets($merchant);

        return ApiResponse::success([
            'wallets' => $wallets->map(static fn ($wallet) => [
                'walletId' => $wallet->id,
                'name' => $wallet->name,
                'walletType' => $wallet->wallet_type?->value,
                'providerCode' => $wallet->providerNetwork?->code?->value,
                'currency' => $wallet->currency,
                'available' => (string) ($wallet->balance?->available ?? '0.0000'),
            ])->values()->all(),
        ]);
    }
}
