<?php

namespace Tests\Feature;

use Database\Seeders\GatewaySeeder;
use Tests\GatewayTestCase;

class AdminDashboardTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_admin_can_fetch_platform_dashboard(): void
    {
        $login = $this->postJson('/api/admin/v1/login', [
            'email' => 'admin@lipahuru.test',
            'password' => 'password',
        ]);

        $token = $login->json('data.token');

        $response = $this->getJson('/api/admin/v1/dashboard', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonStructure([
                'data' => [
                    'collectionsToday',
                    'disbursementsToday',
                    'pendingCount',
                    'failedCount',
                    'merchantCount',
                    'activeMerchantCount',
                    'parentWallet',
                    'providerWallets',
                    'recentTransactions',
                    'currency',
                ],
            ]);
    }
}
