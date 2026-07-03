<?php

namespace App\Repositories\Eloquent;

use App\Enums\PaymentOperation;
use App\Models\ProviderRoute;
use App\Repositories\Contracts\ProviderRouteRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentProviderRouteRepository implements ProviderRouteRepositoryInterface
{
    public function findActiveRoutes(int $providerNetworkId, PaymentOperation $operation): Collection
    {
        return ProviderRoute::query()
            ->with('paymentProvider')
            ->where('provider_network_id', $providerNetworkId)
            ->where('operation', $operation)
            ->where('is_active', true)
            ->where('is_healthy', true)
            ->orderBy('priority')
            ->get();
    }

    public function markUnhealthy(int $routeId): void
    {
        ProviderRoute::query()
            ->whereKey($routeId)
            ->update(['is_healthy' => false]);
    }

    public function markHealthy(int $routeId): void
    {
        ProviderRoute::query()
            ->whereKey($routeId)
            ->update(['is_healthy' => true]);
    }
}
