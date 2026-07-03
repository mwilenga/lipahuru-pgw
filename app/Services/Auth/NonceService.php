<?php

namespace App\Services\Auth;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Models\NonceRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NonceService
{
    public function consume(string $clientId, string $nonce): void
    {
        $cacheKey = $this->cacheKey($clientId, $nonce);
        $ttl = (int) config('payment-gateway.nonce_ttl', 600);

        if (Cache::has($cacheKey)) {
            throw new GatewayException(GatewayErrorCode::ReplayProtectionFailed, httpStatus: 401);
        }

        DB::transaction(function () use ($clientId, $nonce, $cacheKey, $ttl): void {
            $exists = NonceRecord::query()
                ->where('client_id', $clientId)
                ->where('nonce', $nonce)
                ->where('expires_at', '>', now())
                ->exists();

            if ($exists) {
                throw new GatewayException(GatewayErrorCode::ReplayProtectionFailed, httpStatus: 401);
            }

            NonceRecord::query()->create([
                'client_id' => $clientId,
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds($ttl),
                'created_at' => now(),
            ]);

            Cache::put($cacheKey, true, $ttl);
        });
    }

    public function isReplay(string $clientId, string $nonce): bool
    {
        $cacheKey = $this->cacheKey($clientId, $nonce);

        if (Cache::has($cacheKey)) {
            return true;
        }

        return NonceRecord::query()
            ->where('client_id', $clientId)
            ->where('nonce', $nonce)
            ->where('expires_at', '>', now())
            ->exists();
    }

    private function cacheKey(string $clientId, string $nonce): string
    {
        return "gateway:nonce:{$clientId}:{$nonce}";
    }
}
