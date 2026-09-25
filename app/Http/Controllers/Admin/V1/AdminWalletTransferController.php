<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminWalletTransferRejectRequest;
use App\Http\Requests\Admin\V1\AdminWalletTransferStoreRequest;
use App\Http\Resources\WalletTransferResource;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Services\Wallet\WalletTransferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWalletTransferController extends Controller
{
    public function __construct(
        private readonly WalletTransferService $walletTransferService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->walletTransferService->listForAdmin(
            filters: $request->only(['status', 'search', 'from', 'to', 'merchantId']),
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

    public function store(AdminWalletTransferStoreRequest $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $merchant = Merchant::query()->findOrFail((int) $request->validated('merchantId'));

        $transfer = $this->walletTransferService->createDirectByAdmin(
            merchant: $merchant,
            admin: $admin,
            fromWalletId: (int) $request->validated('fromWalletId'),
            toWalletId: (int) $request->validated('toWalletId'),
            amount: (string) $request->validated('amount'),
            reference: $request->validated('reference'),
            notes: $request->validated('notes'),
        );

        return ApiResponse::success(
            new WalletTransferResource($transfer),
            'Transfer completed successfully.',
        );
    }

    public function transferableWallets(Request $request, int $merchantId): JsonResponse
    {
        $merchant = Merchant::query()->findOrFail($merchantId);
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

    public function approve(Request $request, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $transfer = $this->walletTransferService->findOrFail($id);
        $approved = $this->walletTransferService->approve($transfer, $admin);

        return ApiResponse::success(
            new WalletTransferResource($approved),
            'Transfer approved successfully.',
        );
    }

    public function reject(AdminWalletTransferRejectRequest $request, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $transfer = $this->walletTransferService->findOrFail($id);
        $rejected = $this->walletTransferService->reject(
            $transfer,
            $admin,
            $request->validated('reason'),
        );

        return ApiResponse::success(
            new WalletTransferResource($rejected),
            'Transfer rejected.',
        );
    }
}
