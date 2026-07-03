<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use Database\Seeders\GatewaySeeder;
use Tests\GatewayTestCase;

class MerchantManagementTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_admin_can_create_and_approve_merchant(): void
    {
        $login = $this->postJson('/api/admin/v1/login', [
            'email' => 'admin@lipahuru.test',
            'password' => 'password',
        ]);

        $token = $login->json('data.token');

        $create = $this->postJson('/api/admin/v1/merchants', [
            'name' => 'Acme Ltd',
            'email' => 'acme@test.com',
            'phone' => '255700000001',
        ], ['Authorization' => 'Bearer '.$token]);

        $create->assertOk();
        $merchantId = $create->json('data.merchant.id');

        $approve = $this->postJson("/api/admin/v1/merchants/{$merchantId}/approve", [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $approve->assertOk()
            ->assertJsonPath('data.status', MerchantStatus::Active->value);
    }
}
