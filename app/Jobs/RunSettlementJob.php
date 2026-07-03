<?php

namespace App\Jobs;

use App\Services\Settlement\SettlementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class RunSettlementJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?Carbon $settlementDate = null,
    ) {
        $this->onQueue('settlements');
    }

    public function handle(SettlementService $settlementService): void
    {
        $settlementService->batchSettle($this->settlementDate);
    }
}
