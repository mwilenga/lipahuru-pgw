<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

class HmacSignatureService
{
    public function buildCanonicalString(
        string $method,
        string $path,
        string $contentSha256,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $contentSha256,
        ]);
    }

    public function sign(string $canonical, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    public function verify(Request $request, string $secret, ?string $previousSecret = null): bool
    {
        $providedSignature = (string) $request->header('X-Signature', '');

        if ($providedSignature === '') {
            return false;
        }

        $canonical = $this->buildCanonicalString(
            $request->method(),
            '/'.$request->path(),
            $this->hashRequestBody($request->getContent()),
        );

        if (hash_equals($this->sign($canonical, $secret), $providedSignature)) {
            return true;
        }

        if ($previousSecret !== null && $previousSecret !== '') {
            return hash_equals($this->sign($canonical, $previousSecret), $providedSignature);
        }

        return false;
    }

    public function hashRequestBody(string $body): string
    {
        return base64_encode(hash('sha256', $body, true));
    }
}
