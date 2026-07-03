<?php

namespace App\Providers\Payment\Flutterwave;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Providers\Payment\Contracts\PaymentProviderInterface;
use App\Providers\Payment\DTOs\CollectionRequest;
use App\Providers\Payment\DTOs\DisbursementRequest;
use App\Providers\Payment\DTOs\ProviderResponse;
use App\Providers\Payment\DTOs\ProviderStatusResponse;
use App\Providers\Payment\DTOs\ProviderWebhookEvent;
use App\Providers\Payment\DTOs\RefundRequest;
use Illuminate\Http\Request;

class FlutterwaveProvider implements PaymentProviderInterface
{
    public function getDriverName(): string
    {
        return 'flutterwave';
    }

    public function initiateCollection(CollectionRequest $req): ProviderResponse
    {
        $this->notConfigured();
    }

    public function initiateDisbursement(DisbursementRequest $req): ProviderResponse
    {
        $this->notConfigured();
    }

    public function queryStatus(string $providerRef): ProviderStatusResponse
    {
        $this->notConfigured();
    }

    public function initiateRefund(RefundRequest $req): ProviderResponse
    {
        $this->notConfigured();
    }

    public function verifyWebhook(Request $request): ProviderWebhookEvent
    {
        $this->notConfigured();
    }

    private function notConfigured(): never
    {
        throw new GatewayException(
            GatewayErrorCode::GeneralError,
            'Flutterwave provider is not configured.',
            501,
        );
    }
}
