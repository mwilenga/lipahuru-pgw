<?php

namespace App\Providers;

use App\Repositories\Contracts\IdempotencyRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\OAuthClientRepositoryInterface;
use App\Repositories\Contracts\ProviderRouteRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Eloquent\EloquentIdempotencyRepository;
use App\Repositories\Eloquent\EloquentMerchantRepository;
use App\Repositories\Eloquent\EloquentOAuthClientRepository;
use App\Repositories\Eloquent\EloquentProviderRouteRepository;
use App\Repositories\Eloquent\EloquentTransactionRepository;
use App\Repositories\Eloquent\EloquentWalletRepository;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MerchantRepositoryInterface::class, EloquentMerchantRepository::class);
        $this->app->bind(TransactionRepositoryInterface::class, EloquentTransactionRepository::class);
        $this->app->bind(WalletRepositoryInterface::class, EloquentWalletRepository::class);
        $this->app->bind(IdempotencyRepositoryInterface::class, EloquentIdempotencyRepository::class);
        $this->app->bind(OAuthClientRepositoryInterface::class, EloquentOAuthClientRepository::class);
        $this->app->bind(ProviderRouteRepositoryInterface::class, EloquentProviderRouteRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::tokensExpireIn(now()->addSeconds((int) config('payment-gateway.token_ttl', 900)));
        Passport::personalAccessTokensExpireIn(now()->addSeconds((int) config('payment-gateway.token_ttl', 900)));
        Passport::tokensCan([
            'gateway:payments' => 'Initiate and query payments',
        ]);
        Passport::defaultScopes(['gateway:payments']);
    }
}
