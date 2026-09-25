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
        $link = $this->portalLink('/admin/float-topups', ['approve' => (string) $topup->id]);

        $message = "LipaHuru: Float topup pending from {$merchantName}. ID {$topup->topup_id}. Amount {$amount} {$currency}.";
        if ($link !== null) {
            $message .= " Approve: {$link}";
        } else {
            $message .= ' Please approve in portal.';
        }

        $this->smsClient->send($message);
    }

    public function notifyWalletTransfer(WalletTransfer $transfer): void
    {
        $transfer->loadMissing('merchant');

        $merchantName = $transfer->merchant?->name ?? 'Unknown merchant';
        $amount = $this->formatAmount((string) $transfer->amount);
        $currency = $transfer->currency ?? 'TZS';
        $link = $this->portalLink('/admin/transfers', ['approve' => (string) $transfer->id]);

        $message = "LipaHuru: Fund transfer pending from {$merchantName}. ID {$transfer->transfer_id}. Amount {$amount} {$currency}.";
        if ($link !== null) {
            $message .= " Approve: {$link}";
        } else {
            $message .= ' Please approve in portal.';
        }

        $this->smsClient->send($message);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function portalLink(string $path, array $query = []): ?string
    {
        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/');
        if ($base === '') {
            return null;
        }

        $url = $base.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    private function formatAmount(string $amount): string
    {
        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }

        return $amount === '' ? '0' : $amount;
    }
}
