<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HmacSignatureService
{
    public function buildCanonicalString(
        string $method,
        string $path,
        string $clientId,
        string $requestId,
        string $timestamp,
        string $nonce,
        string $contentSha256,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $clientId,
            $requestId,
            $timestamp,
            $nonce,
            $contentSha256,
        ]);
    }

    public function buildCallbackCanonicalString(
        string $callbackId,
        string $timestamp,
        string $contentSha256,
    ): string {
        return implode("\n", [$callbackId, $timestamp, $contentSha256]);
    }

    public function sign(string $canonical, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    public function verify(Request $request, string $signingSecret, ?string $previousSigningSecret = null): bool
    {
        $providedSignature = (string) $request->header('X-Signature', '');

        if ($providedSignature === '') {
            return false;
        }

        $canonical = $this->buildCanonicalString(
            $request->method(),
            '/'.$request->path(),
            (string) $request->header('X-Client-Id', ''),
            (string) $request->header('X-Request-Id', ''),
            (string) $request->header('X-Timestamp', ''),
            (string) $request->header('X-Nonce', ''),
            (string) $request->header('X-Content-SHA256', ''),
        );

        if (hash_equals($this->sign($canonical, $signingSecret), $providedSignature)) {
            return true;
        }

        if ($previousSigningSecret !== null && $previousSigningSecret !== '') {
            return hash_equals($this->sign($canonical, $previousSigningSecret), $providedSignature);
        }

        return false;
    }

    /**
     * @return array{callback_id: string, timestamp: string, content_sha256: string, signature: string}
     */
    public function generateCallbackSignature(string $payload, string $callbackSecret): array
    {
        $callbackId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();
        $contentSha256 = hash('sha256', $payload);
        $canonical = $this->buildCallbackCanonicalString($callbackId, $timestamp, $contentSha256);
        $signature = $this->sign($canonical, $callbackSecret);

        return [
            'callback_id' => $callbackId,
            'timestamp' => $timestamp,
            'content_sha256' => $contentSha256,
            'signature' => $signature,
        ];
    }

    public function hashRequestBody(string $body): string
    {
        return base64_encode(hash('sha256', $body, true));
    }
}
