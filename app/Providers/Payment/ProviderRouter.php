<?php

namespace App\Providers\Payment;

use App\Enums\GatewayErrorCode;
use App\Enums\PaymentOperation;
use App\Enums\ProviderCode;
use App\Exceptions\GatewayException;
use App\Models\ProviderNetwork;
use App\Providers\Payment\AzamPay\AzamPayProvider;
use App\Providers\Payment\Contracts\PaymentProviderInterface;
use App\Providers\Payment\Crdb\CrdbProvider;
use App\Providers\Payment\Flutterwave\FlutterwaveProvider;
use App\Providers\Payment\GoDigital\GoDigitalProvider;
use App\Providers\Payment\Nmb\NmbProvider;
use App\Providers\Payment\Selcom\SelcomProvider;
use App\Repositories\Contracts\ProviderRouteRepositoryInterface;
use Illuminate\Contracts\Container\Container;

class ProviderRouter
{
    /**
     * @var array<string, class-string<PaymentProviderInterface>>
     */
    private const DRIVER_MAP = [
        'godigital' => GoDigitalProvider::class,
        'azampay' => AzamPayProvider::class,
        'selcom' => SelcomProvider::class,
        'flutterwave' => FlutterwaveProvider::class,
        'nmb' => NmbProvider::class,
        'crdb' => CrdbProvider::class,
    ];

    public function __construct(
        private readonly ProviderRouteRepositoryInterface $providerRouteRepository,
        private readonly Container $container,
    ) {}

    public function resolve(string $providerCode, PaymentOperation $operation): PaymentProviderInterface
    {
        $networkCode = ProviderCode::tryFrom(strtoupper($providerCode));

        if ($networkCode === null) {
            throw new GatewayException(
                GatewayErrorCode::UnsupportedProvider,
                "Unknown providerCode [{$providerCode}].",
            );
        }

        $network = ProviderNetwork::query()
            ->where('code', $networkCode)
            ->where('is_active', true)
            ->first();

        if ($network === null) {
            throw new GatewayException(
                GatewayErrorCode::UnsupportedProvider,
                "Provider network [{$providerCode}] is not active.",
            );
        }

        $route = $this->providerRouteRepository
            ->findActiveRoutes($network->id, $operation)
            ->first();

        if ($route === null || ! $route->paymentProvider) {
            throw new GatewayException(
                GatewayErrorCode::UnsupportedProvider,
                "No active route for providerCode [{$providerCode}] and operation [{$operation->value}].",
            );
        }

        $driver = strtolower($route->paymentProvider->driver);

        if (! isset(self::DRIVER_MAP[$driver])) {
            throw new GatewayException(
                GatewayErrorCode::UnsupportedProvider,
                "Payment driver [{$driver}] is not registered.",
            );
        }

        return $this->container->make(self::DRIVER_MAP[$driver]);
    }

    public function resolveByDriver(string $driver): PaymentProviderInterface
    {
        $driver = strtolower($driver);

        if (! isset(self::DRIVER_MAP[$driver])) {
            throw new GatewayException(
                GatewayErrorCode::UnsupportedProvider,
                "Payment driver [{$driver}] is not registered.",
            );
        }

        return $this->container->make(self::DRIVER_MAP[$driver]);
    }
}
