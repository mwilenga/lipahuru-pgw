<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderNetworkResource;
use App\Http\Resources\ProviderResource;
use App\Http\Resources\ProviderRouteResource;
use App\Models\PaymentProvider;
use App\Models\ProviderNetwork;
use App\Models\ProviderRoute;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminProviderController extends Controller
{
    public function providers(): JsonResponse
    {
        $providers = PaymentProvider::query()->orderBy('name')->get();

        return ApiResponse::success(ProviderResource::collection($providers));
    }

    public function networks(): JsonResponse
    {
        $networks = ProviderNetwork::query()
            ->with(['routes.paymentProvider'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ProviderNetworkResource::collection($networks));
    }

    public function routes(): JsonResponse
    {
        $routes = ProviderRoute::query()
            ->with(['providerNetwork', 'paymentProvider'])
            ->orderBy('provider_network_id')
            ->orderBy('priority')
            ->get();

        return ApiResponse::success(ProviderRouteResource::collection($routes));
    }
}
