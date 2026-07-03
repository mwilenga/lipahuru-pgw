<?php

namespace App\Repositories\Eloquent;

use App\Models\Merchant;
use App\Repositories\Contracts\MerchantRepositoryInterface;

class EloquentMerchantRepository implements MerchantRepositoryInterface
{
    public function findById(int $id): ?Merchant
    {
        return Merchant::query()->find($id);
    }

    public function findByUuid(string $uuid): ?Merchant
    {
        return Merchant::query()->where('uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?Merchant
    {
        return Merchant::query()->where('email', $email)->first();
    }

    public function create(array $attributes): Merchant
    {
        return Merchant::query()->create($attributes);
    }

    public function update(Merchant $merchant, array $attributes): Merchant
    {
        $merchant->update($attributes);

        return $merchant->refresh();
    }
}
