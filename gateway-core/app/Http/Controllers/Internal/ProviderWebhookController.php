<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Providers\Payment\ProviderRouter;
use App\Services\Webhook\IncomingProviderWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderWebhookController extends Controller
{
    public function __construct(
        private readonly IncomingProviderWebhookService $webhookService,
        private readonly ProviderRouter $providerRouter,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $adapter = $this->providerRouter->resolveByDriver($provider);
        $event = $adapter->verifyWebhook($request);
        $this->webhookService->process($provider, $event, $request);

        return response()->json(['status' => 'RECEIVED']);
    }
}
