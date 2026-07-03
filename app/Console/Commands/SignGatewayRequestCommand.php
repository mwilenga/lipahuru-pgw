<?php

namespace App\Console\Commands;

use App\Services\Auth\HmacSignatureService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SignGatewayRequestCommand extends Command
{
    protected $signature = 'gateway:sign-request
                            {method : HTTP method e.g. POST or GET}
                            {path : Request path e.g. /api/v1/payments/collections/push}
                            {--body= : JSON request body}
                            {--client-id= : X-Client-Id value}
                            {--signing-secret= : HMAC signing secret}
                            {--token= : Bearer access token}';

    protected $description = 'Generate signed gateway headers for manual API testing (.http / Postman)';

    public function handle(HmacSignatureService $hmac): int
    {
        $method = strtoupper($this->argument('method'));
        $path = $this->argument('path');
        $body = (string) $this->option('body');
        $clientId = (string) ($this->option('client-id') ?: config('providers.godigital.client_id', 'cli_demo'));
        $signingSecret = (string) ($this->option('signing-secret') ?: config('providers.godigital.signing_secret', 'demo-signing-secret'));
        $token = (string) ($this->option('token') ?: 'YOUR_ACCESS_TOKEN');

        $requestId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();
        $nonce = Str::random(32);
        $contentSha256 = $hmac->hashRequestBody($body);
        $idempotencyKey = (string) Str::uuid();

        $canonical = $hmac->buildCanonicalString(
            $method,
            $path,
            $clientId,
            $requestId,
            $timestamp,
            $nonce,
            $contentSha256,
        );

        $signature = $hmac->sign($canonical, $signingSecret);

        $this->line('Authorization: Bearer '.$token);
        $this->line('X-Client-Id: '.$clientId);
        $this->line('X-Request-Id: '.$requestId);
        $this->line('X-Timestamp: '.$timestamp);
        $this->line('X-Nonce: '.$nonce);
        $this->line('X-Content-SHA256: '.$contentSha256);
        $this->line('X-Signature: '.$signature);

        if ($method === 'POST') {
            $this->line('X-Idempotency-Key: '.$idempotencyKey);
        }

        $this->newLine();
        $this->comment('Canonical string:');
        $this->line($canonical);

        return self::SUCCESS;
    }
}
