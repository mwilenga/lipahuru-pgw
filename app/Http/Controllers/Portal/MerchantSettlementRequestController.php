<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\MerchantSettlementRequestStoreRequest;
use App\Http\Resources\SettlementRequestResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\Settlement\SettlementRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantSettlementRequestController extends Controller
{
    public function __construct(
        private readonly SettlementRequestService $settlementRequestService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $paginator = $this->settlementRequestService->listForMerchant(
            merchant: $merchant,
            filters: $request->only(['status', 'search', 'from', 'to']),
            perPage: (int) $request->query('perPage', 25),
        );

        return ApiResponse::success([
            'settlements' => SettlementRequestResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function wallets(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        return ApiResponse::success([
            'wallets' => $this->settlementRequestService->settleableWallets($merchant),
            'commission' => $this->settlementRequestService->commissionSummary($merchant),
            'bankAccount' => $merchant->hasSettlementBankAccount() ? [
                'bankName' => $merchant->settlement_bank_name,
                'accountName' => $merchant->settlement_account_name,
                'accountNumber' => $merchant->settlement_account_number,
                'branch' => $merchant->settlement_bank_branch,
            ] : null,
        ]);
    }

    public function store(MerchantSettlementRequestStoreRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        /** @var MerchantUser $user */
        $user = $request->attributes->get('merchant_user');

        $settlement = $this->settlementRequestService->requestByMerchant(
            merchant: $merchant,
            user: $user,
            walletId: (int) $request->validated('walletId'),
            amount: (string) $request->validated('amount'),
            memo: $request->validated('memo'),
        );

        return ApiResponse::success(
            new SettlementRequestResource($settlement),
            'Settlement request submitted. Waiting for admin approval.',
        );
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $settlement = $this->settlementRequestService->findForMerchantOrFail($id, $merchant);
        $cancelled = $this->settlementRequestService->cancelByMerchant($settlement);

        return ApiResponse::success(
            new SettlementRequestResource($cancelled),
            'Settlement request cancelled.',
        );
    }
}
