<?php

namespace App\Repositories\Contracts;

use App\Models\Merchant;

interface MerchantRepositoryInterface
{
    public function findById(int $id): ?Merchant;

    public function findByUuid(string $uuid): ?Merchant;

    public function findByEmail(string $email): ?Merchant;

    public function create(array $attributes): Merchant;

    public function update(Merchant $merchant, array $attributes): Merchant;
}
