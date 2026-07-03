<?php

namespace App\Models;

use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'merchant_id',
        'provider_network_id',
        'payment_provider_id',
        'request_id',
        'reference',
        'external_reference',
        'operation',
        'status',
        'amount',
        'currency',
        'msisdn',
        'callback_url',
        'narration',
        'provider_transaction_id',
        'provider_receipt_no',
        'failure_code',
        'failure_message',
        'finalized_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'request_id' => 'string',
            'operation' => PaymentOperation::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:4',
            'metadata' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function balanceReservation(): HasOne
    {
        return $this->hasOne(BalanceReservation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function settlementItems(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
