<?php

namespace Tests\Feature;

use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class AuthenticationTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_oauth_token_endpoint_issues_bearer_token(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $this->plainClientSecret = $credentials['client_secret'];

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }

    public function test_oauth_token_rejects_invalid_credentials(): void
    {
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'invalid',
            'client_secret' => 'invalid',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'PGW-1007');
    }

    public function test_admin_can_login(): void
    {
        $response = $this->postJson('/api/admin/v1/login', [
            'email' => 'admin@lipahuru.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token', fn ($value) => ! empty($value));
    }

    public function test_portal_login_routes_admin_by_email(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@lipahuru.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.token', fn ($value) => ! empty($value));
    }

    public function test_portal_login_routes_merchant_by_email(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();

        \App\Models\MerchantUser::query()->create([
            'merchant_id' => $credentials['merchant']->id,
            'name' => 'Owner',
            'email' => 'owner@merchant.test',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'owner@merchant.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'merchant')
            ->assertJsonPath('data.user.merchantId', $credentials['merchant']->id)
            ->assertJsonPath('data.token', fn ($value) => ! empty($value));
    }

    public function test_portal_login_rejects_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'PGW-1007');
    }
}
