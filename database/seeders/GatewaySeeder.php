<?php

namespace Database\Seeders;

use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Models\ProviderNetwork;
use App\Models\ProviderRoute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;

class GatewaySeeder extends Seeder
{
    public function run(): void
    {
        $this->ensurePassportClients();
        AdminUser::query()->updateOrCreate(
            ['email' => env('GATEWAY_ADMIN_EMAIL', 'admin@lipahuru.test')],
            [
                'name' => env('GATEWAY_ADMIN_NAME', 'Gateway Admin'),
                'password' => Hash::make(env('GATEWAY_ADMIN_PASSWORD', 'password')),
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        $goDigital = PaymentProvider::query()->updateOrCreate(
            ['code' => 'GODIGITAL'],
            [
                'name' => 'GoDigital Payment Gateway',
                'driver' => 'godigital',
                'is_active' => true,
                'config' => [
                    'base_url' => env('GODIGITAL_BASE_URL'),
                    'client_id' => env('GODIGITAL_CLIENT_ID'),
                    'client_secret' => env('GODIGITAL_CLIENT_SECRET'),
                ],
            ],
        );

        PaymentProvider::query()->updateOrCreate(
            ['code' => 'AZAMPAY'],
            [
                'name' => 'AzamPay',
                'driver' => 'azampay',
                'is_active' => true,
                'config' => [
                    'base_url' => env('AZAMPAY_BASE_URL'),
                    'app_name' => env('AZAMPAY_APP_NAME'),
                    'client_id' => env('AZAMPAY_CLIENT_ID'),
                    'client_secret' => env('AZAMPAY_CLIENT_SECRET'),
                ],
            ],
        );

        $defaultProvider = $goDigital;

        $networks = [
            ProviderCode::Yas->value => 'Yas (Tigo)',
            ProviderCode::Vodacom->value => 'Vodacom M-Pesa',
            ProviderCode::Halotel->value => 'Halotel',
            ProviderCode::Airtel->value => 'Airtel Money',
        ];

        foreach ($networks as $code => $name) {
            $network = ProviderNetwork::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true],
            );

            foreach (PaymentOperation::cases() as $operation) {
                ProviderRoute::query()->updateOrCreate(
                    [
                        'provider_network_id' => $network->id,
                        'operation' => $operation,
                        'priority' => 1,
                    ],
                    [
                        'payment_provider_id' => $defaultProvider->id,
                        'is_active' => true,
                        'is_healthy' => true,
                    ],
                );
            }
        }
    }

    private function ensurePassportClients(): void
    {
        $repository = app(ClientRepository::class);

        if (\Laravel\Passport\Client::query()->where('provider', 'merchants')->doesntExist()) {
            $repository->createPersonalAccessGrantClient('Lipahuru Merchants', 'merchants');
        }
    }
}
