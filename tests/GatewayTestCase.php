<?php

namespace Tests;

use App\Enums\MerchantStatus;
use App\Enums\ProviderCode;
use App\Models\Merchant;
use App\Models\MerchantProviderProfile;
use App\Models\OAuthClient;
use App\Models\ProviderNetwork;
use App\Services\Auth\HmacSignatureService;
use Database\Seeders\GatewaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

abstract class GatewayTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    protected function createActiveMerchantWithCredentials(): array
    {
        $onboarding = app(\App\Services\Merchant\MerchantOnboardingService::class);
        $result = $onboarding->onboard([
            'name' => 'Test Merchant',
            'email' => 'merchant'.Str::random(6).'@test.com',
            'default_callback_url' => 'https://merchant.test/callback',
        ]);

        $merchant = $result['merchant'];
        $merchant->update(['status' => MerchantStatus::Active, 'approved_at' => now()]);

        foreach (ProviderCode::cases() as $code) {
            $network = ProviderNetwork::query()->where('code', $code)->first();
            if ($network) {
                MerchantProviderProfile::query()->updateOrCreate(
                    ['merchant_id' => $merchant->id, 'provider_network_id' => $network->id],
                    ['is_enabled' => true, 'min_amount' => 100, 'max_amount' => 10000000],
                );
            }
        }

        return $result;
    }

    protected function getAccessToken(string $clientId, string $clientSecret): string
    {
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $response->assertOk();

        return (string) $response->json('access_token');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function signedHeaders(
        string $method,
        string $path,
        array $payload,
        string $clientSecret,
        ?string $idempotencyKey = null,
    ): array {
        $body = $method === 'GET' ? '' : json_encode($payload);
        $hmac = app(HmacSignatureService::class);
        $contentSha256 = $hmac->hashRequestBody($body);

        $canonical = $hmac->buildCanonicalString(
            $method,
            $path,
            $contentSha256,
        );

        $headers = [
            'Authorization' => 'Bearer '.($this->cachedToken ?? ''),
            'X-Signature' => $hmac->sign($canonical, $clientSecret),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    protected ?string $cachedToken = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function signedPost(
        string $path,
        array $payload,
        Merchant $merchant,
        OAuthClient $client,
        string $clientSecret,
        ?string $idempotencyKey = null,
    ) {
        if ($this->cachedToken === null) {
            $this->cachedToken = $this->getAccessToken(
                $client->client_id,
                $clientSecret,
            );
        }

        $fullPath = '/api'.$path;
        $headers = $this->signedHeaders('POST', $fullPath, $payload, $clientSecret, $idempotencyKey);
        $headers['Authorization'] = 'Bearer '.$this->cachedToken;

        return $this->postJson('/api'.$path, $payload, $headers);
    }

    protected ?string $plainClientSecret = null;
}
