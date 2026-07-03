<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingWebhookLog extends Model
{
    protected $fillable = [
        'provider_code',
        'event_type',
        'headers',
        'payload',
        'status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
