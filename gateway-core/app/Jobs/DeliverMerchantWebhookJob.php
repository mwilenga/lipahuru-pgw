<?php

namespace App\Jobs;

use App\Services\Webhook\MerchantWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverMerchantWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $deliveryId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(MerchantWebhookService $merchantWebhookService): void
    {
        $merchantWebhookService->attemptDelivery($this->deliveryId);
    }
}
