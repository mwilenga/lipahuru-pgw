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
        $path = '/api/v1/payments/collections/push';
        $contentSha256 = $hmac->hashRequestBody($body);

        $canonical = $hmac->buildCanonicalString('POST', $path, $contentSha256);

        $response = $this->postJson('/api/v1/payments/collections/push', $payload, [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Signature' => $hmac->sign($canonical, $credentials['client_secret']),
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
        $path = '/api/v1/payments/collections/push';
        $contentSha256 = $hmac->hashRequestBody($body);

        $canonical = $hmac->buildCanonicalString('POST', $path, $contentSha256);

        $createResponse = $this->postJson('/api/v1/payments/collections/push', $createPayload, [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Signature' => $hmac->sign($canonical, $credentials['client_secret']),
        ]);

        $transactionId = $createResponse->json('data.transactionId');

        $getPath = '/api/v1/payments/'.$transactionId;
        $emptyHash = $hmac->hashRequestBody('');

        $getCanonical = $hmac->buildCanonicalString('GET', $getPath, $emptyHash);

        $response = $this->call('GET', '/api/v1/payments/'.$transactionId, [], [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
            'HTTP_X-Signature' => $hmac->sign($getCanonical, $credentials['client_secret']),
            'HTTP_Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.transactionId', $transactionId);
    }
}
