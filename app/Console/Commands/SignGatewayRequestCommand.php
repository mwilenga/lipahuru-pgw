<?php

namespace App\Console\Commands;

use App\Services\Auth\HmacSignatureService;
use Illuminate\Console\Command;

class SignGatewayRequestCommand extends Command
{
    protected $signature = 'gateway:sign-request
                            {method : HTTP method e.g. POST or GET}
                            {path : Request path e.g. /api/v1/payments/collections/push}
                            {--body= : JSON request body}
                            {--client-secret= : Client secret used for HMAC signing}
                            {--token= : Bearer access token}';

    protected $description = 'Generate signed gateway headers for manual API testing (.http / Postman)';

    public function handle(HmacSignatureService $hmac): int
    {
        $method = strtoupper($this->argument('method'));
        $path = $this->argument('path');
        $body = (string) $this->option('body');
        $clientSecret = (string) ($this->option('client-secret') ?: 'YOUR_CLIENT_SECRET');
        $token = (string) ($this->option('token') ?: 'YOUR_ACCESS_TOKEN');

        $contentSha256 = $hmac->hashRequestBody($body);

        $canonical = $hmac->buildCanonicalString(
            $method,
            $path,
            $contentSha256,
        );

        $signature = $hmac->sign($canonical, $clientSecret);

        $this->line('Authorization: Bearer '.$token);
        $this->line('X-Signature: '.$signature);

        if ($method === 'POST') {
            $this->line('X-Idempotency-Key: '.(string) \Illuminate\Support\Str::uuid());
        }

        $this->newLine();
        $this->comment('Canonical string:');
        $this->line($canonical);

        return self::SUCCESS;
    }
}
