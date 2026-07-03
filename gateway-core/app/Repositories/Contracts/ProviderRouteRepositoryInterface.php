<?php

namespace App\Repositories\Contracts;

use App\Enums\PaymentOperation;
use Illuminate\Support\Collection;

interface ProviderRouteRepositoryInterface
{
    public function findActiveRoutes(int $providerNetworkId, PaymentOperation $operation): Collection;

    public function markUnhealthy(int $routeId): void;

    public function markHealthy(int $routeId): void;
}
