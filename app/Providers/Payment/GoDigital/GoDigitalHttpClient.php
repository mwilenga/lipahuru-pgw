<?php

namespace App\Providers\Payment\GoDigital;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Services\Auth\HmacSignatureService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoDigitalHttpClient
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly HmacSignatureService $hmac,
    ) {}

    public function isMockMode(): bool
    {
        $config = config('providers.godigital');

        return empty($config['client_id'])
            || empty($config['client_secret']);
    }

    public function merchantId(): string
    {
        $merchantId = (string) config('providers.godigital.merchant_id', '');

        if ($merchantId === '' && ! $this->isMockMode()) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'GoDigital merchant ID is not configured. Set GODIGITAL_MERCHANT_ID in .env.',
                500,
            );
        }

        return $merchantId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload, bool $idempotent = true): Response
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->send('POST', $path, $body, $idempotent);
    }

    public function get(string $path): Response
    {
        return $this->send('GET', $path, '', false);
    }

    private function send(string $method, string $path, string $body, bool $idempotent): Response
    {
        $headers = $this->buildSignedHeaders($method, $path, $body, $idempotent);
        $url = $this->normalizedBaseUrl().$path;
        /** @var array<string, mixed>|null $payload */
        $payload = $body !== '' ? json_decode($body, true) : null;

        Log::info('GoDigital outbound request', [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'payload' => $payload,
            'callbackUrl' => is_array($payload) ? ($payload['callbackUrl'] ?? null) : null,
            'configuredCallbackUrl' => config('providers.godigital.callback_url'),
            'headers' => $this->redactHeadersForLog($headers),
        ]);

        $request = $this->client()->withHeaders($headers);

        if ($method === 'GET') {
            $response = $request->get($path);
        } else {
            $response = $request->withBody($body, 'application/json')->post($path);
        }

        Log::info('GoDigital outbound response', [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'httpStatus' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]);

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function redactHeadersForLog(array $headers): array
    {
        $safe = $headers;

        if (isset($safe['Authorization'])) {
            $safe['Authorization'] = 'Bearer [REDACTED]';
        }

        if (isset($safe['X-Signature'])) {
            $safe['X-Signature'] = '[REDACTED]';
        }

        return $safe;
    }

    /**
     * @return array<string, string>
     */
    private function buildSignedHeaders(string $method, string $path, string $body, bool $idempotent): array
    {
        $signingSecret = (string) config('providers.godigital.signing_secret', config('providers.godigital.client_secret'));
        $clientId = (string) config('providers.godigital.client_id');
        $contentSha256 = $this->hmac->hashRequestBody($body);
        $requestId = (string) Str::uuid();
        $timestamp = (string) now()->getTimestamp();
        $nonce = Str::random(32);

        $canonical = $this->hmac->buildCanonicalString(
            $method,
            $path,
            $contentSha256,
        );

        $headers = [
            'Authorization' => 'Bearer '.$this->token(),
            'X-Client-Id' => $clientId,
            'X-Request-Id' => $requestId,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Content-SHA256' => $contentSha256,
            'X-Signature' => $this->hmac->sign($canonical, $signingSecret),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($idempotent) {
            $headers['X-Idempotency-Key'] = (string) Str::uuid();
        }

        return $headers;
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $oauthPath = (string) config('providers.godigital.oauth_path', '/api/v1/oauth/token');
        $oauthUrl = $this->normalizedBaseUrl().$oauthPath;

        Log::info('GoDigital OAuth token request', [
            'method' => 'POST',
            'url' => $oauthUrl,
            'path' => $oauthPath,
            'clientId' => config('providers.godigital.client_id'),
        ]);

        $response = $this->baseRequest()
            ->asForm()
            ->post($oauthPath, [
                'grant_type' => 'client_credentials',
                'client_id' => config('providers.godigital.client_id'),
                'client_secret' => config('providers.godigital.client_secret'),
            ]);

        if ($response->failed()) {
            $detail = trim($response->body()) !== ''
                ? $response->body()
                : 'HTTP '.$response->status().' (empty body)';

            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'Failed to authenticate with GoDigital: '.$detail,
                502,
            );
        }

        $this->accessToken = (string) $response->json('access_token', '');

        if ($this->accessToken === '') {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                'GoDigital returned an empty access token.',
                502,
            );
        }

        return $this->accessToken;
    }

    private function client(): PendingRequest
    {
        return $this->baseRequest()->acceptJson();
    }

    private function baseRequest(): PendingRequest
    {
        $request = Http::baseUrl($this->normalizedBaseUrl())
            ->timeout((int) config('providers.godigital.timeout', 30));

        if (! config('providers.godigital.verify_ssl', true)) {
            $request = $request->withOptions(['verify' => false]);
        }

        return $request;
    }

    private function normalizedBaseUrl(): string
    {
        $base = rtrim((string) config('providers.godigital.base_url'), '/');

        if (str_ends_with($base, '/api/v1')) {
            $base = substr($base, 0, -strlen('/api/v1'));
        }

        return rtrim($base, '/');
    }
}
