<?php

namespace App\Services\Merchant;

use App\Enums\GatewayErrorCode;
use App\Enums\MerchantStatus;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MerchantService
{
    public function __construct(
        private readonly MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function list(int $perPage = 25): LengthAwarePaginator
    {
        return Merchant::query()->latest()->paginate($perPage);
    }

    public function findById(int $id): Merchant
    {
        $merchant = $this->merchantRepository->findById($id);

        if ($merchant === null) {
            throw new GatewayException(GatewayErrorCode::GeneralError, 'Merchant not found', httpStatus: 404);
        }

        return $merchant;
    }

    public function findByUuid(string $uuid): Merchant
    {
        $merchant = $this->merchantRepository->findByUuid($uuid);

        if ($merchant === null) {
            throw new GatewayException(GatewayErrorCode::GeneralError, 'Merchant not found', httpStatus: 404);
        }

        return $merchant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Merchant
    {
        return $this->merchantRepository->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Merchant $merchant, array $attributes): Merchant
    {
        return $this->merchantRepository->update($merchant, $attributes);
    }

    public function approve(Merchant $merchant): Merchant
    {
        if ($merchant->status === MerchantStatus::Active) {
            return $merchant;
        }

        return $this->merchantRepository->update($merchant, [
            'status' => MerchantStatus::Active,
            'approved_at' => now(),
        ]);
    }

    public function suspend(Merchant $merchant, ?string $reason = null): Merchant
    {
        $metadata = $merchant->metadata ?? [];

        if ($reason !== null) {
            $metadata['suspension_reason'] = $reason;
            $metadata['suspended_at'] = now()->toIso8601String();
        }

        return $this->merchantRepository->update($merchant, [
            'status' => MerchantStatus::Suspended,
            'metadata' => $metadata,
        ]);
    }

    public function reject(Merchant $merchant, ?string $reason = null): Merchant
    {
        $metadata = $merchant->metadata ?? [];

        if ($reason !== null) {
            $metadata['rejection_reason'] = $reason;
        }

        return $this->merchantRepository->update($merchant, [
            'status' => MerchantStatus::Rejected,
            'metadata' => $metadata,
        ]);
    }
}
