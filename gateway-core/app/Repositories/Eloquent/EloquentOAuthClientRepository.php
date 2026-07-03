<?php

namespace App\Repositories\Eloquent;

use App\Models\OAuthClient;
use App\Repositories\Contracts\OAuthClientRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentOAuthClientRepository implements OAuthClientRepositoryInterface
{
    public function findByClientId(string $clientId): ?OAuthClient
    {
        return OAuthClient::query()
            ->where('client_id', $clientId)
            ->whereNull('revoked_at')
            ->where('status', 'ACTIVE')
            ->first();
    }

    public function findActiveByMerchant(int $merchantId): Collection
    {
        return OAuthClient::query()
            ->where('merchant_id', $merchantId)
            ->whereNull('revoked_at')
            ->where('status', 'ACTIVE')
            ->get();
    }

    public function create(array $attributes): OAuthClient
    {
        return OAuthClient::query()->create($attributes);
    }

    public function revoke(OAuthClient $client): OAuthClient
    {
        $client->update([
            'status' => 'REVOKED',
            'revoked_at' => now(),
        ]);

        return $client->refresh();
    }
}
