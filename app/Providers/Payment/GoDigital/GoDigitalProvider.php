<?php

namespace App\Providers\Payment\GoDigital;

use App\Enums\GatewayErrorCode;
use App\Enums\TransactionStatus;
use App\Exceptions\GatewayException;
use App\Providers\Payment\Contracts\PaymentProviderInterface;
use App\Providers\Payment\DTOs\CollectionRequest;
use App\Providers\Payment\DTOs\DisbursementRequest;
use App\Providers\Payment\DTOs\ProviderResponse;
use App\Providers\Payment\DTOs\ProviderStatusResponse;
use App\Providers\Payment\DTOs\ProviderWebhookEvent;
use App\Providers\Payment\DTOs\RefundRequest;
use App\Services\Auth\HmacSignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoDigitalProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly GoDigitalHttpClient $client,
        private readonly HmacSignatureService $hmac,
    ) {}

    public function getDriverName(): string
    {
        return 'godigital';
    }

    public function initiateCollection(CollectionRequest $req): ProviderResponse
    {
        if ($this->client->isMockMode()) {
            return $this->mockResponse($req->transactionId, TransactionStatus::Acknowledged);
        }

        $payload = [
            'requestId' => (string) Str::uuid(),
            'merchantId' => $this->client->merchantId(),
            'providerCode' => strtoupper($req->providerCode),
            'amount' => (float) $req->amount,
            'currency' => $req->currency,
            'msisdn' => $req->msisdn,
            'reference' => $req->reference,
            'callbackUrl' => $req->callbackUrl ?? config('providers.godigital.callback_url'),
            'narration' => $req->narration,
        ];

        $response = $this->client->post('/api/v1/payments/collections/push', $payload);

        return $this->mapInitiationResponse($response, $req->transactionId);
    }

    public function initiateDisbursement(DisbursementRequest $req): ProviderResponse
    {
        if ($this->client->isMockMode()) {
            return $this->mockResponse($req->transactionId, TransactionStatus::FundsReserved);
        }

        $payload = [
            'requestId' => (string) Str::uuid(),
            'merchantId' => $this->client->merchantId(),
            'providerCode' => strtoupper($req->providerCode),
            'amount' => (float) $req->amount,
            'currency' => $req->currency,
            'msisdn' => $req->msisdn,
            'reference' => $req->reference,
            'callbackUrl' => $req->callbackUrl ?? config('providers.godigital.callback_url'),
            'narration' => $req->narration,
        ];

        $response = $this->client->post('/api/v1/payments/disbursements', $payload);

        return $this->mapInitiationResponse($response, $req->transactionId);
    }

    public function queryStatus(string $providerRef): ProviderStatusResponse
    {
        if ($this->client->isMockMode()) {
            return new ProviderStatusResponse(
                providerReference: $providerRef,
                status: TransactionStatus::Success,
                rawResponse: ['mock' => true, 'providerRef' => $providerRef],
            );
        }

        $response = $this->client->get('/api/v1/payments/'.$providerRef);

        if ($response->failed()) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'GoDigital status query failed: '.$response->body(),
                502,
            );
        }

        $data = $response->json('data', []) ?? [];
        $status = $this->mapStatus((string) ($data['transactionStatus'] ?? $data['status'] ?? 'PENDING'));

        return new ProviderStatusResponse(
            providerReference: $providerRef,
            status: $status,
            amount: isset($data['amount']) ? (string) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            providerReceiptNo: $data['providerReceiptNo'] ?? null,
            failureCode: $response->json('code'),
            failureMessage: $response->json('message'),
            rawResponse: $response->json() ?? [],
        );
    }

    public function initiateRefund(RefundRequest $req): ProviderResponse
    {
        if ($this->client->isMockMode()) {
            return $this->mockResponse($req->refundId, TransactionStatus::PendingFinal);
        }

        throw new GatewayException(
            GatewayErrorCode::GeneralError,
            'GoDigital refunds are not defined in the upstream API specification.',
        );
    }

    public function verifyWebhook(Request $request): ProviderWebhookEvent
    {
        $callbackSecret = (string) config('providers.godigital.callback_secret');

        if ($callbackSecret !== '' && ! $this->verifyCallbackSignature($request, $callbackSecret)) {
            throw new GatewayException(GatewayErrorCode::SignatureFailed, 'Invalid GoDigital callback signature.', 401);
        }

        $payload = $request->all();
        $data = $payload['data'] ?? $payload;

        $providerTransactionId = (string) ($data['providerTransactionId'] ?? $data['transactionId'] ?? '');
        $status = $this->mapStatus((string) ($data['transactionStatus'] ?? $data['status'] ?? 'PENDING'));

        return new ProviderWebhookEvent(
            providerTransactionId: $providerTransactionId,
            status: $status,
            eventType: (string) ($payload['eventType'] ?? 'PAYMENT_FINALIZED'),
            payload: $payload,
            providerReceiptNo: $data['providerReceiptNo'] ?? null,
            failureCode: $data['failureCode'] ?? null,
            failureMessage: $data['message'] ?? null,
        );
    }

    private function verifyCallbackSignature(Request $request, string $callbackSecret): bool
    {
        $callbackId = (string) $request->header('X-Callback-Id', '');
        $timestamp = (string) $request->header('X-Callback-Timestamp', '');
        $providedSignature = (string) $request->header('X-Callback-Signature', '');
        $providedHash = (string) $request->header('X-Callback-Content-SHA256', '');

        if ($callbackId === '' || $timestamp === '' || $providedSignature === '') {
            return false;
        }

        $bodyHash = $this->hmac->hashRequestBody($request->getContent());

        if (! hash_equals($bodyHash, $providedHash)) {
            return false;
        }

        $canonical = $this->hmac->buildCallbackCanonicalString($callbackId, $timestamp, $bodyHash);

        return hash_equals($this->hmac->sign($canonical, $callbackSecret), $providedSignature);
    }

    private function mapInitiationResponse(\Illuminate\Http\Client\Response $response, string $fallbackRef): ProviderResponse
    {
        $envelope = $response->json() ?? [];

        if ($response->failed() || ($envelope['status'] ?? '') === 'FAILED') {
            return new ProviderResponse(
                success: false,
                status: TransactionStatus::Failed,
                failureCode: (string) ($envelope['code'] ?? 'GODIGITAL_ERROR'),
                failureMessage: (string) ($envelope['message'] ?? 'GoDigital request failed'),
                rawResponse: $envelope,
            );
        }

        $data = $envelope['data'] ?? [];
        $transactionStatus = $this->mapStatus((string) ($data['transactionStatus'] ?? 'ACKNOWLEDGED'));

        return new ProviderResponse(
            success: true,
            status: $transactionStatus,
            providerTransactionId: (string) ($data['transactionId'] ?? $fallbackRef),
            providerReceiptNo: $data['providerReceiptNo'] ?? null,
            rawResponse: $envelope,
        );
    }

    private function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtoupper($providerStatus)) {
            'SUCCESS' => TransactionStatus::Success,
            'FAILED' => TransactionStatus::Failed,
            'REVERSED' => TransactionStatus::Reversed,
            'RECONCILING' => TransactionStatus::Reconciling,
            'FUNDS_RESERVED' => TransactionStatus::FundsReserved,
            'PENDING_FINAL' => TransactionStatus::PendingFinal,
            'ACKNOWLEDGED' => TransactionStatus::Acknowledged,
            default => TransactionStatus::PendingFinal,
        };
    }

    private function mockResponse(string $reference, TransactionStatus $status): ProviderResponse
    {
        return new ProviderResponse(
            success: true,
            status: $status,
            providerTransactionId: 'GD-'.Str::upper(Str::random(12)),
            providerReceiptNo: 'RCPT-'.Str::upper(Str::random(8)),
            rawResponse: [
                'mock' => true,
                'sandbox' => true,
                'reference' => $reference,
                'driver' => 'godigital',
            ],
        );
    }
}
