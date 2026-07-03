<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\Report\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class GenerateDailyReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?Carbon $date = null,
    ) {
        $this->onQueue('reports');
    }

    public function handle(ReportService $reportService): void
    {
        $date = $this->date ?? now()->subDay()->startOfDay();

        Merchant::query()
            ->where('status', 'ACTIVE')
            ->chunkById(50, function ($merchants) use ($reportService, $date): void {
                foreach ($merchants as $merchant) {
                    $reportService->aggregateDailySummary($merchant, $date);
                }
            });
    }
}
