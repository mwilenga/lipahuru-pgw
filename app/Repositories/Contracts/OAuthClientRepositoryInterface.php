<?php

namespace App\Repositories\Contracts;

use App\Models\OAuthClient;
use Illuminate\Support\Collection;

interface OAuthClientRepositoryInterface
{
    public function findByClientId(string $clientId): ?OAuthClient;

    public function findActiveByMerchant(int $merchantId): Collection;

    public function create(array $attributes): OAuthClient;

    public function revoke(OAuthClient $client): OAuthClient;
}
