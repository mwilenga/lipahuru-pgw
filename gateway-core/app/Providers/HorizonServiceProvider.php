<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::routeMailNotificationsTo(config('mail.from.address'));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return app()->environment('local');
        });
    }
}
