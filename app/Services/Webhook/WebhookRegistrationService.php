<?php

namespace App\Services\Webhook;

use App\Models\Merchant;
use App\Models\MerchantWebhook;
use App\Models\WebhookDelivery;
use App\Services\Merchant\ApiCredentialService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebhookRegistrationService
{
    public function __construct(
        private readonly ApiCredentialService $apiCredentialService,
        private readonly MerchantWebhookService $merchantWebhookService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function register(Merchant $merchant, array $payload): MerchantWebhook
    {
        $callbackSecret = $this->apiCredentialService->getCallbackSecret($merchant);

        return MerchantWebhook::query()->create([
            'merchant_id' => $merchant->id,
            'url' => $payload['url'],
            'secret' => $callbackSecret ?? 'whsec_'.Str::random(48),
            'is_active' => $payload['is_active'] ?? true,
            'events' => $payload['events'] ?? ['PAYMENT_FINALIZED'],
        ]);
    }

    /**
     * @return Collection<int, MerchantWebhook>
     */
    public function listForMerchant(Merchant $merchant): Collection
    {
        return MerchantWebhook::query()
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function retryDelivery(int $deliveryId, ?int $merchantId = null): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->findOrFail($deliveryId);

        if ($merchantId !== null && $delivery->merchant_id !== $merchantId) {
            abort(404);
        }

        $delivery->update([
            'status' => 'PENDING',
            'next_retry_at' => now(),
        ]);

        return $this->merchantWebhookService->attemptDelivery($delivery->id);
    }

    public function listDeliveries(?int $merchantId = null, int $perPage = 25): LengthAwarePaginator
    {
        return WebhookDelivery::query()
            ->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId))
            ->with('transaction')
            ->latest()
            ->paginate($perPage);
    }
}
