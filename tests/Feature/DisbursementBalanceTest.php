<?php

namespace Tests\Feature;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Enums\WalletType;
use App\Models\BalanceReservation;
use App\Models\Merchant;
use App\Models\MerchantCommission;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Auth\HmacSignatureService;
use App\Services\Payment\PaymentService;
use Database\Seeders\GatewaySeeder;
use Illuminate\Support\Str;
use Tests\GatewayTestCase;

class DisbursementBalanceTest extends GatewayTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GatewaySeeder::class);
    }

    public function test_disbursement_reserves_payout_plus_commission(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        /** @var Merchant $merchant */
        $merchant = $credentials['merchant'];
        $this->setB2cFixedFee($merchant, '600');
        $wallet = $this->creditDisbursementWallet($merchant, ProviderCode::Vodacom->value, '1600');

        $response = $this->postDisbursement($credentials, [
            'amount' => 1000.00,
            'providerCode' => ProviderCode::Vodacom->value,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS');

        $wallet->refresh()->load('balance');
        $this->assertSame('0.0000', (string) $wallet->balance->available);
        $this->assertSame('1600.0000', (string) $wallet->balance->reserved);
        $this->assertSame('1600.0000', (string) $wallet->balance->total);

        $transactionId = $response->json('data.transactionId');
        $transaction = Transaction::query()->where('transaction_id', $transactionId)->firstOrFail();

        $this->assertSame('1000.0000', (string) $transaction->amount);
        $this->assertSame(
            '1600.0000',
            (string) BalanceReservation::query()->where('transaction_id', $transaction->id)->value('amount'),
        );
    }

    public function test_disbursement_rejected_when_balance_covers_payout_but_not_fee(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        /** @var Merchant $merchant */
        $merchant = $credentials['merchant'];
        $this->setB2cFixedFee($merchant, '600');
        $wallet = $this->creditDisbursementWallet($merchant, ProviderCode::Vodacom->value, '1000');

        $this->postDisbursement($credentials, [
            'amount' => 1000.00,
            'providerCode' => ProviderCode::Vodacom->value,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PGW-1006');

        $wallet->refresh()->load('balance');
        $this->assertSame('1000.0000', (string) $wallet->balance->available);
        $this->assertSame('0.0000', (string) $wallet->balance->reserved);
    }

    public function test_finalize_success_consumes_payout_plus_commission(): void
    {
        $credentials = $this->createActiveMerchantWithCredentials();
        /** @var Merchant $merchant */
        $merchant = $credentials['merchant'];
        $this->setB2cFixedFee($merchant, '600');
        $wallet = $this->creditDisbursementWallet($merchant, ProviderCode::Vodacom->value, '1600');

        $transactionId = $this->postDisbursement($credentials, [
            'amount' => 1000.00,
            'providerCode' => ProviderCode::Vodacom->value,
        ])->json('data.transactionId');

        $transaction = Transaction::query()->where('transaction_id', $transactionId)->firstOrFail();
        app(PaymentService::class)->finalizeSuccess($transaction);

        $wallet->refresh()->load('balance');
        $this->assertSame('0.0000', (string) $wallet->balance->available);
        $this->assertSame('0.0000', (string) $wallet->balance->reserved);
        $this->assertSame('0.0000', (string) $wallet->balance->total);
    }

    private function setB2cFixedFee(Merchant $merchant, string $value): void
    {
        MerchantCommission::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'operation' => PaymentOperation::B2cDisbursement],
            ['commission_type' => CommissionType::Fixed, 'value' => $value],
        );
    }

    private function creditDisbursementWallet(Merchant $merchant, string $providerCode, string $amount): Wallet
    {
        $wallet = $merchant->wallets()
            ->where('wallet_type', WalletType::DisbursementLeaf)
            ->whereHas('providerNetwork', fn ($query) => $query->where('code', $providerCode))
            ->with('balance')
            ->firstOrFail();

        $wallet->balance->update([
            'available' => $amount,
            'reserved' => '0.0000',
            'total' => $amount,
        ]);

        return $wallet->refresh()->load('balance');
    }

    /**
     * @param  array{merchant: Merchant, client_id: string, client_secret: string}  $credentials
     * @param  array{amount: float, providerCode: string}  $overrides
     */
    private function postDisbursement(array $credentials, array $overrides)
    {
        $payload = [
            'requestId' => (string) Str::uuid(),
            'providerCode' => $overrides['providerCode'],
            'amount' => $overrides['amount'],
            'currency' => 'TZS',
            'msisdn' => '255754123456',
            'reference' => 'PAY-'.Str::random(8),
            'callbackUrl' => 'https://merchant.test/callback',
            'narration' => 'Disbursement balance test',
        ];

        $token = $this->getAccessToken($credentials['client_id'], $credentials['client_secret']);
        $hmac = app(HmacSignatureService::class);
        $path = '/api/v1/payments/disbursements';
        $body = json_encode($payload);
        $canonical = $hmac->buildCanonicalString('POST', $path, $hmac->hashRequestBody($body));

        return $this->postJson($path, $payload, [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Signature' => $hmac->sign($canonical, $credentials['client_secret']),
        ]);
    }
}
