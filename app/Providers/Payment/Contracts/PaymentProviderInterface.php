<?php

namespace App\Providers\Payment\Contracts;

use App\Providers\Payment\DTOs\CollectionRequest;
use App\Providers\Payment\DTOs\DisbursementRequest;
use App\Providers\Payment\DTOs\ProviderResponse;
use App\Providers\Payment\DTOs\ProviderStatusResponse;
use App\Providers\Payment\DTOs\ProviderWebhookEvent;
use App\Providers\Payment\DTOs\RefundRequest;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    public function initiateCollection(CollectionRequest $req): ProviderResponse;

    public function initiateDisbursement(DisbursementRequest $req): ProviderResponse;

    public function queryStatus(string $providerRef): ProviderStatusResponse;

    public function initiateRefund(RefundRequest $req): ProviderResponse;

    public function verifyWebhook(Request $request): ProviderWebhookEvent;

    public function getDriverName(): string;
}
