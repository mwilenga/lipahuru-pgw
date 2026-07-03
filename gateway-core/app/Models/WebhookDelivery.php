<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'callback_id',
        'merchant_id',
        'transaction_id',
        'event_type',
        'url',
        'payload',
        'attempt',
        'max_attempts',
        'status',
        'http_status',
        'response_body',
        'next_retry_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'callback_id' => 'string',
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
