<?php

use App\Jobs\GenerateDailyReportJob;
use App\Jobs\PollProviderStatusJob;
use App\Jobs\PurgeExpiredNoncesJob;
use App\Jobs\ReconcileTransactionJob;
use App\Jobs\RetryFailedWebhooksJob;
use App\Jobs\RunSettlementJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PollProviderStatusJob)->everyTwoMinutes();
Schedule::job(new RetryFailedWebhooksJob)->everyFifteenMinutes();
Schedule::job(new ReconcileTransactionJob)->hourly();
Schedule::job(new RunSettlementJob)->dailyAt('02:00');
Schedule::job(new GenerateDailyReportJob)->dailyAt('03:00');
Schedule::job(new PurgeExpiredNoncesJob)->dailyAt('04:00');
