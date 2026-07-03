<?php

namespace App\Providers\Payment;

use App\Providers\Payment\AzamPay\AzamPayProvider;
use App\Providers\Payment\Crdb\CrdbProvider;
use App\Providers\Payment\Flutterwave\FlutterwaveProvider;
use App\Providers\Payment\GoDigital\GoDigitalHttpClient;
use App\Providers\Payment\GoDigital\GoDigitalProvider;
use App\Providers\Payment\Nmb\NmbProvider;
use App\Providers\Payment\Selcom\SelcomProvider;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoDigitalHttpClient::class);
        $this->app->singleton(GoDigitalProvider::class);
        $this->app->singleton(AzamPayProvider::class);
        $this->app->singleton(SelcomProvider::class);
        $this->app->singleton(FlutterwaveProvider::class);
        $this->app->singleton(NmbProvider::class);
        $this->app->singleton(CrdbProvider::class);
        $this->app->singleton(ProviderRouter::class);
    }
}
