<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderPerformanceMetric extends Model
{
    protected $fillable = [
        'payment_provider_id',
        'metric_date',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'avg_latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
        ];
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }
}
