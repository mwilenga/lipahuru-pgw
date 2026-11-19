<?php

namespace App\Services\Provider;

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use App\Models\ProviderFeeRate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProviderFeeRateService
{
    /**
     * @return Collection<int, ProviderFeeRate>
     */
    public function list(?bool $activeOnly = null): Collection
    {
        return ProviderFeeRate::query()
            ->with('providerNetwork')
            ->when($activeOnly !== null, fn ($q) => $q->where('is_active', $activeOnly))
            ->orderByDesc('is_active')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array{providerNetworkId: int, operation: string, commissionType: string, value: numeric, effectiveFrom?: string|null, notes?: string|null, isActive?: bool}  $payload
     */
    public function create(array $payload): ProviderFeeRate
    {
        return DB::transaction(function () use ($payload): ProviderFeeRate {
            $networkId = (int) $payload['providerNetworkId'];
            $operation = PaymentOperation::from($payload['operation']);
            $isActive = array_key_exists('isActive', $payload)
                ? (bool) $payload['isActive']
                : true;

            if ($isActive) {
                $this->deactivateOthers($networkId, $operation);
            }

            return ProviderFeeRate::query()->create([
                'provider_network_id' => $networkId,
                'operation' => $operation,
                'commission_type' => CommissionType::from($payload['commissionType']),
                'value' => $payload['value'],
                'is_active' => $isActive,
                'effective_from' => $payload['effectiveFrom'] ?? now(),
                'notes' => $payload['notes'] ?? null,
            ])->load('providerNetwork');
        });
    }

    /**
     * @param  array{providerNetworkId: int, operation: string, commissionType: string, value: numeric, effectiveFrom?: string|null, notes?: string|null, isActive?: bool}  $payload
     */
    public function update(ProviderFeeRate $rate, array $payload): ProviderFeeRate
    {
        return DB::transaction(function () use ($rate, $payload): ProviderFeeRate {
            $networkId = (int) $payload['providerNetworkId'];
            $operation = PaymentOperation::from($payload['operation']);
            $isActive = array_key_exists('isActive', $payload)
                ? (bool) $payload['isActive']
                : (bool) $rate->is_active;

            if ($isActive) {
                $this->deactivateOthers($networkId, $operation, $rate->id);
            }

            $rate->update([
                'provider_network_id' => $networkId,
                'operation' => $operation,
                'commission_type' => CommissionType::from($payload['commissionType']),
                'value' => $payload['value'],
                'is_active' => $isActive,
                'effective_from' => $payload['effectiveFrom'] ?? $rate->effective_from,
                'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $rate->notes,
            ]);

            return $rate->refresh()->load('providerNetwork');
        });
    }

    public function delete(ProviderFeeRate $rate): void
    {
        $rate->delete();
    }

    private function deactivateOthers(
        int $networkId,
        PaymentOperation $operation,
        ?int $exceptId = null,
    ): void {
        ProviderFeeRate::query()
            ->where('provider_network_id', $networkId)
            ->where('operation', $operation)
            ->where('is_active', true)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_active' => false]);
    }
}
