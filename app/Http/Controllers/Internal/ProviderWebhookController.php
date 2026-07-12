<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Providers\Payment\ProviderRouter;
use App\Services\Webhook\IncomingProviderWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProviderWebhookController extends Controller
{
    public function __construct(
        private readonly IncomingProviderWebhookService $webhookService,
        private readonly ProviderRouter $providerRouter,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $payload = $request->all();
        $data = $payload['data'] ?? $payload;

        Log::info('GoDigital inbound webhook received', [
            'provider' => strtolower($provider),
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'ip' => $request->ip(),
            'eventType' => $payload['eventType'] ?? null,
            'transactionStatus' => $data['transactionStatus'] ?? $data['status'] ?? null,
            'transactionId' => $data['transactionId'] ?? null,
            'providerTransactionId' => $data['providerTransactionId'] ?? null,
            'reference' => $data['reference'] ?? null,
            'payload' => $payload,
            'headers' => $request->headers->all(),
        ]);

        $adapter = $this->providerRouter->resolveByDriver($provider);
        $event = $adapter->verifyWebhook($request);
        $this->webhookService->process($provider, $event, $request);

        return response()->json(['status' => 'RECEIVED']);
    }
}
