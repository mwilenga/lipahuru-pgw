<?php

namespace Tests\Feature;

use App\Enums\WalletType;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Wallet;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Facades\Http;
use Tests\GatewayTestCase;

class AdminApprovalSmsTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);

        config([
            'app.frontend_url' => 'https://portal.lipahuru.test',
            'services.kilakona_sms.api_key' => 'test-api-key',
            'services.kilakona_sms.api_secret' => 'test-api-secret',
            'services.kilakona_sms.send_url' => 'https://messaging.kilakona.test/api/v1/vendor/message/send',
            'services.kilakona_sms.sender_id' => 'NIALIKE',
            'services.kilakona_sms.recipients' => ['255700000001', '255700000002'],
        ]);
    }

    public function test_merchant_float_topup_sends_sms_to_admin_recipients(): void
    {
        Http::fake([
            'https://messaging.kilakona.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        [$merchant, $token] = $this->createMerchantPortalSession();

        $response = $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/float-topups', [
                'items' => [
                    ['providerCode' => 'VODACOM', 'amount' => 5000],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'PENDING');

        $topupId = (string) $response->json('data.topupId');
        $numericId = (string) $response->json('data.id');
        $approveUrl = "https://portal.lipahuru.test/admin/float-topups?approve={$numericId}";

        Http::assertSent(function ($request) use ($merchant, $topupId, $approveUrl) {
            if ($request->url() !== 'https://messaging.kilakona.test/api/v1/vendor/message/send') {
                return false;
            }

            $body = $request->data();

            return $request->hasHeader('api_key', 'test-api-key')
                && $request->hasHeader('api_secret', 'test-api-secret')
                && ($body['senderId'] ?? null) === 'NIALIKE'
                && ($body['messageType'] ?? null) === 'text'
                && ($body['contacts'] ?? null) === '255700000001,255700000002'
                && str_contains((string) ($body['message'] ?? ''), 'Float topup pending')
                && str_contains((string) ($body['message'] ?? ''), $merchant->name)
                && str_contains((string) ($body['message'] ?? ''), $topupId)
                && str_contains((string) ($body['message'] ?? ''), '5000')
                && str_contains((string) ($body['message'] ?? ''), $approveUrl);
        });
    }

    public function test_merchant_wallet_transfer_sends_sms_to_admin_recipients(): void
    {
        Http::fake([
            'https://messaging.kilakona.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        [$merchant, $token] = $this->createMerchantPortalSession();
        $source = $this->fundWallet($merchant, WalletType::CollectionLeaf, 'VODACOM', '10000');
        $destination = $this->leafWallet($merchant, WalletType::DisbursementLeaf, 'VODACOM');

        $response = $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/wallet-transfers', [
                'fromWalletId' => $source->id,
                'toWalletId' => $destination->id,
                'amount' => 2500,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL');

        $transferId = (string) $response->json('data.transferId');
        $numericId = (string) $response->json('data.id');
        $approveUrl = "https://portal.lipahuru.test/admin/transfers?approve={$numericId}";

        Http::assertSent(function ($request) use ($merchant, $transferId, $approveUrl) {
            $body = $request->data();

            return $request->url() === 'https://messaging.kilakona.test/api/v1/vendor/message/send'
                && ($body['contacts'] ?? null) === '255700000001,255700000002'
                && str_contains((string) ($body['message'] ?? ''), 'Fund transfer pending')
                && str_contains((string) ($body['message'] ?? ''), $merchant->name)
                && str_contains((string) ($body['message'] ?? ''), $transferId)
                && str_contains((string) ($body['message'] ?? ''), '2500')
                && str_contains((string) ($body['message'] ?? ''), $approveUrl);
        });
    }

    public function test_no_sms_when_recipients_empty(): void
    {
        config(['services.kilakona_sms.recipients' => []]);

        Http::fake();

        [, $token] = $this->createMerchantPortalSession();

        $this->withToken($token, 'Bearer')
            ->postJson('/api/v1/portal/float-topups', [
                'items' => [
                    ['providerCode' => 'VODACOM', 'amount' => 1000],
                ],
            ])
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_admin_direct_float_topup_does_not_send_sms(): void
    {
        Http::fake();

        [$merchant] = $this->createMerchantPortalSession();
        $adminToken = $this->adminToken();

        $this->withToken($adminToken, 'Bearer')
            ->postJson('/api/admin/v1/float-topups', [
                'merchantId' => $merchant->id,
                'items' => [
                    ['providerCode' => 'VODACOM', 'amount' => 1000],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        Http::assertNothingSent();
    }

    /**
     * @return array{0: Merchant, 1: string}
     */
    private function createMerchantPortalSession(): array
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        $merchant = $credentials['merchant'];

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Portal Owner',
            'email' => 'owner-'.uniqid('', true).'@test.com',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('merchant-dashboard')->plainTextToken;

        return [$merchant, $token];
    }

    private function adminToken(): string
    {
        $admin = AdminUser::query()->where('email', 'admin@lipahuru.test')->firstOrFail();

        return $admin->createToken('admin-dashboard')->plainTextToken;
    }

    private function leafWallet(Merchant $merchant, WalletType $type, string $providerCode): Wallet
    {
        return $merchant->wallets()
            ->where('wallet_type', $type)
            ->whereHas('providerNetwork', fn ($query) => $query->where('code', $providerCode))
            ->with('balance')
            ->firstOrFail();
    }

    private function fundWallet(Merchant $merchant, WalletType $type, string $providerCode, string $amount): Wallet
    {
        $wallet = $this->leafWallet($merchant, $type, $providerCode);
        $normalized = number_format((float) $amount, 4, '.', '');

        $wallet->balance->update([
            'available' => bcadd((string) $wallet->balance->available, $normalized, 4),
            'total' => bcadd((string) $wallet->balance->total, $normalized, 4),
        ]);

        return $wallet->refresh()->load('balance');
    }
}
