<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WebhookRegisterRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookResource;
use App\Models\Merchant;
use App\Services\Webhook\WebhookRegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookRegistrationService $webhookRegistrationService,
    ) {}

    public function store(WebhookRegisterRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $webhook = $this->webhookRegistrationService->register($merchant, $request->validated());

        return ApiResponse::success(
            new WebhookResource($webhook),
        );
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $webhooks = $this->webhookRegistrationService->listForMerchant($merchant);

        return ApiResponse::success(
            WebhookResource::collection($webhooks),
        );
    }

    public function deliveries(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $paginator = $this->webhookRegistrationService->listDeliveries(
            $merchant->id,
            (int) $request->query('perPage', 25),
        );

        return ApiResponse::success([
            'deliveries' => WebhookDeliveryResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $delivery = $this->webhookRegistrationService->retryDelivery($id, $merchant->id);

        return ApiResponse::success(
            new WebhookDeliveryResource($delivery->loadMissing('transaction')),
        );
    }
}
