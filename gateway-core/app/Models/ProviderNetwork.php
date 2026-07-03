<?php

namespace App\Models;

use App\Enums\ProviderCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderNetwork extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => ProviderCode::class,
            'is_active' => 'boolean',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(ProviderRoute::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function merchantProfiles(): HasMany
    {
        return $this->hasMany(MerchantProviderProfile::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
