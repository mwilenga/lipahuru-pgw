<?php

namespace App\Models;

use App\Enums\MerchantStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;

class Merchant extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'legal_name',
        'email',
        'phone',
        'registration_number',
        'tax_id',
        'status',
        'environment',
        'default_currency',
        'default_callback_url',
        'metadata',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MerchantStatus::class,
            'metadata' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MerchantCommission::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function oauthClients(): HasMany
    {
        return $this->hasMany(OAuthClient::class);
    }

    public function apiCredential(): HasOne
    {
        return $this->hasOne(ApiCredential::class);
    }

    public function providerProfiles(): HasMany
    {
        return $this->hasMany(MerchantProviderProfile::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(MerchantWebhook::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function idempotencyRecords(): HasMany
    {
        return $this->hasMany(IdempotencyRecord::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailyMerchantSummary::class);
    }
}
