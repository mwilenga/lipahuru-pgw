<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    protected $fillable = [
        'code',
        'name',
        'driver',
        'is_active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(ProviderRoute::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(ProviderPerformanceMetric::class);
    }
}
