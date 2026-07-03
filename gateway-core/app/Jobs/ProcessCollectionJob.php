<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\Payment\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCollectionJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $merchantId,
        public array $payload,
    ) {
        $this->onQueue('payments');
    }

    public function handle(PaymentService $paymentService): void
    {
        $merchant = Merchant::query()->findOrFail($this->merchantId);
        $paymentService->createCollection($merchant, $this->payload);
    }
}
