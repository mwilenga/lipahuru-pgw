<?php

namespace App\Jobs;

use App\Models\NonceRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeExpiredNoncesJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        NonceRecord::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
