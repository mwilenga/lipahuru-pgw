<?php

namespace App\Services\Sms;

use App\Models\FloatTopup;
use App\Models\WalletTransfer;

class AdminApprovalSmsNotifier
{
    public function __construct(
        private readonly KilakonaSmsClient $smsClient,
    ) {}

    public function notifyFloatTopup(FloatTopup $topup): void
    {
        $topup->loadMissing('merchant');

        $merchantName = $topup->merchant?->name ?? 'Unknown merchant';
        $amount = $this->formatAmount((string) $topup->total_amount);
        $currency = $topup->currency ?? 'TZS';

        $this->smsClient->send(
            "LipaHuru: Float topup pending from {$merchantName}. ID {$topup->topup_id}. Amount {$amount} {$currency}. Please approve.",
        );
    }

    public function notifyWalletTransfer(WalletTransfer $transfer): void
    {
        $transfer->loadMissing('merchant');

        $merchantName = $transfer->merchant?->name ?? 'Unknown merchant';
        $amount = $this->formatAmount((string) $transfer->amount);
        $currency = $transfer->currency ?? 'TZS';

        $this->smsClient->send(
            "LipaHuru: Fund transfer pending from {$merchantName}. ID {$transfer->transfer_id}. Amount {$amount} {$currency}. Please approve.",
        );
    }

    private function formatAmount(string $amount): string
    {
        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }

        return $amount === '' ? '0' : $amount;
    }
}
