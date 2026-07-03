<?php

namespace App\Providers\Payment\AzamPay;

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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AzamPayProvider implements PaymentProviderInterface
{
    private ?string $accessToken = null;

    public function getDriverName(): string
    {
        return 'azampay';
    }

    public function initiateCollection(CollectionRequest $req): ProviderResponse
    {
        $provider = $this->resolveNetwork($req->providerCode);

        if ($this->isMockMode()) {
            return $this->mockResponse($req->transactionId, TransactionStatus::Acknowledged);
        }

        $payload = [
            'accountNumber' => $req->msisdn,
            'amount' => $req->amount,
            'currency' => $req->currency,
            'externalId' => $req->transactionId,
            'provider' => $provider,
            'additionalProperties' => [
                'reference' => $req->reference,
                'narration' => $req->narration,
            ],
        ];

        $response = $this->client()
            ->withToken($this->token())
            ->post('/azampay/mmo/checkout', $payload);

        if ($response->failed()) {
            return new ProviderResponse(
                success: false,
                status: TransactionStatus::Failed,
                failureCode: (string) $response->json('errorCode', 'AZAMPAY_ERROR'),
                failureMessage: (string) $response->json('message', 'AzamPay collection request failed'),
                rawResponse: $response->json() ?? [],
            );
        }

        $data = $response->json() ?? [];

        return new ProviderResponse(
            success: true,
            status: TransactionStatus::Acknowledged,
            providerTransactionId: (string) ($data['transactionId'] ?? $data['internalReference'] ?? $req->transactionId),
            providerReceiptNo: $data['receiptNumber'] ?? null,
            rawResponse: $data,
        );
    }

    public function initiateDisbursement(DisbursementRequest $req): ProviderResponse
    {
        $provider = $this->resolveNetwork($req->providerCode);

        if ($this->isMockMode()) {
            return $this->mockResponse($req->transactionId, TransactionStatus::Acknowledged);
        }

        $payload = [
            'accountNumber' => $req->msisdn,
            'amount' => $req->amount,
            'currency' => $req->currency,
            'externalId' => $req->transactionId,
            'provider' => $provider,
            'remarks' => $req->narration,
        ];

        $response = $this->client()
            ->withToken($this->token())
            ->post('/azampay/mmo/disburse', $payload);

        if ($response->failed()) {
            return new ProviderResponse(
                success: false,
                status: TransactionStatus::Failed,
                failureCode: (string) $response->json('errorCode', 'AZAMPAY_ERROR'),
                failureMessage: (string) $response->json('message', 'AzamPay disbursement request failed'),
                rawResponse: $response->json() ?? [],
            );
        }

        $data = $response->json() ?? [];

        return new ProviderResponse(
            success: true,
            status: TransactionStatus::Acknowledged,
            providerTransactionId: (string) ($data['transactionId'] ?? $data['internalReference'] ?? $req->transactionId),
            providerReceiptNo: $data['receiptNumber'] ?? null,
            rawResponse: $data,
        );
    }

    public function queryStatus(string $providerRef): ProviderStatusResponse
    {
        if ($this->isMockMode()) {
            return new ProviderStatusResponse(
                providerReference: $providerRef,
                status: TransactionStatus::Success,
                rawResponse: ['mock' => true, 'providerRef' => $providerRef],
            );
        }

        $response = $this->client()
            ->withToken($this->token())
            ->get('/azampay/transaction/status', ['transactionId' => $providerRef]);

        if ($response->failed()) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'AzamPay status query failed.',
                502,
            );
        }

        $data = $response->json() ?? [];
        $status = $this->mapStatus((string) ($data['status'] ?? 'PENDING'));

        return new ProviderStatusResponse(
            providerReference: $providerRef,
            status: $status,
            amount: isset($data['amount']) ? (string) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            failureCode: $data['errorCode'] ?? null,
            failureMessage: $data['message'] ?? null,
            rawResponse: $data,
        );
    }

    public function initiateRefund(RefundRequest $req): ProviderResponse
    {
        if ($this->isMockMode()) {
            return $this->mockResponse($req->refundId, TransactionStatus::PendingFinal);
        }

        throw new GatewayException(
            GatewayErrorCode::GeneralError,
            'AzamPay refunds are not yet implemented.',
        );
    }

    public function verifyWebhook(Request $request): ProviderWebhookEvent
    {
        $payload = $request->all();
        $providerTransactionId = (string) ($payload['transactionId'] ?? $payload['externalId'] ?? '');
        $status = $this->mapStatus((string) ($payload['status'] ?? 'PENDING'));

        return new ProviderWebhookEvent(
            providerTransactionId: $providerTransactionId,
            status: $status,
            eventType: (string) ($payload['eventType'] ?? 'payment.status'),
            payload: $payload,
            providerReceiptNo: $payload['receiptNumber'] ?? null,
            failureCode: $payload['errorCode'] ?? null,
            failureMessage: $payload['message'] ?? null,
        );
    }

    private function isMockMode(): bool
    {
        $config = config('providers.azampay');

        return empty($config['client_id']) || empty($config['client_secret']);
    }

    private function mockResponse(string $reference, TransactionStatus $status): ProviderResponse
    {
        return new ProviderResponse(
            success: true,
            status: $status,
            providerTransactionId: 'MOCK-'.Str::upper(Str::random(12)),
            providerReceiptNo: 'RCPT-'.Str::upper(Str::random(8)),
            rawResponse: [
                'mock' => true,
                'sandbox' => true,
                'reference' => $reference,
            ],
        );
    }

    private function resolveNetwork(string $providerCode): string
    {
        $networkMap = config('providers.azampay.network_map', []);
        $normalized = strtoupper($providerCode);

        if (! isset($networkMap[$normalized])) {
            throw new GatewayException(
                GatewayErrorCode::UnsupportedProvider,
                "Network [{$providerCode}] is not mapped for AzamPay.",
            );
        }

        return $networkMap[$normalized];
    }

    private function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtoupper($providerStatus)) {
            'SUCCESS', 'SUCCESSFUL', 'COMPLETED' => TransactionStatus::Success,
            'FAILED', 'FAILURE', 'REJECTED' => TransactionStatus::Failed,
            'PENDING', 'PROCESSING', 'INPROGRESS' => TransactionStatus::PendingFinal,
            default => TransactionStatus::Acknowledged,
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('providers.azampay.base_url'), '/'))
            ->timeout((int) config('providers.azampay.timeout', 30))
            ->acceptJson()
            ->asJson();
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $response = $this->client()->post('/AppRegistration/GenerateToken', [
            'clientId' => config('providers.azampay.client_id'),
            'clientSecret' => config('providers.azampay.client_secret'),
            'appName' => config('providers.azampay.app_name'),
        ]);

        if ($response->failed()) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'Failed to authenticate with AzamPay.',
                502,
            );
        }

        $this->accessToken = (string) $response->json('data.accessToken', $response->json('accessToken', ''));

        if ($this->accessToken === '') {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'AzamPay returned an empty access token.',
                502,
            );
        }

        return $this->accessToken;
    }
}
