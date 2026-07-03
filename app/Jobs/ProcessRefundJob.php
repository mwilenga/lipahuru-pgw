<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\Refund\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRefundJob implements ShouldQueue
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

    public function handle(RefundService $refundService): void
    {
        $merchant = Merchant::query()->findOrFail($this->merchantId);
        $refundService->createRefund($merchant, $this->payload);
    }
}
