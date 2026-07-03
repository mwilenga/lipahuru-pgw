<?php

namespace Tests\Feature;

use App\Enums\ProviderCode;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class PaymentFlowTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_merchant_can_initiate_c2b_collection(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $this->plainClientSecret = $credentials['client_secret'];
        $token = $this->getAccessToken($credentials['client_id'], $credentials['client_secret']);

        $payload = [
            'requestId' => (string) Str::uuid(),
            'providerCode' => ProviderCode::Vodacom->value,
            'amount' => 10000.00,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'reference' => 'INV-'.Str::random(8),
            'callbackUrl' => 'https://merchant.test/callback',
            'narration' => 'Test payment',
        ];

        $body = json_encode($payload);
        $hmac = app(\App\Services\Auth\HmacSignatureService::class);
        $requestId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();
        $nonce = Str::random(32);
        $contentSha256 = base64_encode(hash('sha256', $body, true));
        $path = '/api/v1/payments/collections/push';

        $canonical = $hmac->buildCanonicalString(
            'POST',
            $path,
            $credentials['client_id'],
            $requestId,
            $timestamp,
            $nonce,
            $contentSha256,
        );

        $response = $this->postJson('/api/v1/payments/collections/push', $payload, [
            'Authorization' => 'Bearer '.$token,
            'X-Client-Id' => $credentials['client_id'],
            'X-Request-Id' => $requestId,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Content-SHA256' => $contentSha256,
            'X-Signature' => $hmac->sign($canonical, $credentials['signing_secret']),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('code', 'PGW-0000')
            ->assertJsonStructure(['data' => ['transactionId', 'transactionStatus']]);
    }

    public function test_merchant_can_query_transaction_status(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $token = $this->getAccessToken($credentials['client_id'], $credentials['client_secret']);

        $createPayload = [
            'requestId' => (string) Str::uuid(),
            'providerCode' => ProviderCode::Vodacom->value,
            'amount' => 5000.00,
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'reference' => 'INV-'.Str::random(8),
        ];

        $body = json_encode($createPayload);
        $hmac = app(\App\Services\Auth\HmacSignatureService::class);
        $requestId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();
        $nonce = Str::random(32);
        $contentSha256 = base64_encode(hash('sha256', $body, true));
        $path = '/api/v1/payments/collections/push';

        $canonical = $hmac->buildCanonicalString(
            'POST', $path, $credentials['client_id'], $requestId, $timestamp, $nonce, $contentSha256,
        );

        $createResponse = $this->postJson('/api/v1/payments/collections/push', $createPayload, [
            'Authorization' => 'Bearer '.$token,
            'X-Client-Id' => $credentials['client_id'],
            'X-Request-Id' => $requestId,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Content-SHA256' => $contentSha256,
            'X-Signature' => $hmac->sign($canonical, $credentials['signing_secret']),
        ]);

        $transactionId = $createResponse->json('data.transactionId');

        $getPath = '/api/v1/payments/'.$transactionId;
        $getRequestId = (string) Str::uuid();
        $getTimestamp = now()->toIso8601String();
        $getNonce = Str::random(32);
        $emptyBody = '';
        $emptyHash = base64_encode(hash('sha256', $emptyBody, true));

        $getCanonical = $hmac->buildCanonicalString(
            'GET', $getPath, $credentials['client_id'], $getRequestId, $getTimestamp, $getNonce, $emptyHash,
        );

        $response = $this->call('GET', '/api/v1/payments/'.$transactionId, [], [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
            'HTTP_X-Client-Id' => $credentials['client_id'],
            'HTTP_X-Request-Id' => $getRequestId,
            'HTTP_X-Timestamp' => $getTimestamp,
            'HTTP_X-Nonce' => $getNonce,
            'HTTP_X-Content-SHA256' => $emptyHash,
            'HTTP_X-Signature' => $hmac->sign($getCanonical, $credentials['signing_secret']),
            'HTTP_Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.transactionId', $transactionId);
    }
}
