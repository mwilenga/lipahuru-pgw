<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RetryFailedWebhooksJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $deliveries = WebhookDelivery::query()
            ->whereIn('status', ['RETRYING', 'FAILED'])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->whereColumn('attempt', '<=', 'max_attempts')
            ->limit(100)
            ->get();

        foreach ($deliveries as $delivery) {
            DeliverMerchantWebhookJob::dispatch($delivery->id);
        }
    }
}
