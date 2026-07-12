<?php

namespace App\Providers\Payment\GoDigital;

final class GoDigitalUserMessage
{
    public const GATEWAY = 'Payment gateway';

    public static function notConfigured(): string
    {
        return self::GATEWAY.' is not configured. Contact support.';
    }

    public static function communicationFailed(): string
    {
        return 'Unable to communicate with the payment gateway. Please try again later.';
    }

    public static function authenticationFailed(): string
    {
        return 'Unable to authenticate with the '.strtolower(self::GATEWAY).'. Please try again later.';
    }

    public static function emptyAccessToken(): string
    {
        return 'The '.strtolower(self::GATEWAY).' returned an invalid access token.';
    }

    public static function statusQueryFailed(): string
    {
        return 'Unable to query payment status from the '.strtolower(self::GATEWAY).'. Please try again later.';
    }

    public static function refundsNotSupported(): string
    {
        return 'Refunds are not supported for this '.strtolower(self::GATEWAY).' route.';
    }

    public static function requestFailed(): string
    {
        return self::GATEWAY.' request failed.';
    }

    public static function forFailedResponse(?string $body, string $fallback): string
    {
        if (self::isHtmlResponse($body)) {
            return self::communicationFailed();
        }

        return $fallback;
    }

    /**
     * For server logs only — never expose raw HTML bodies.
     */
    public static function summarizeUpstreamDetail(?string $detail, ?int $httpStatus = null): ?string
    {
        if ($detail === null || trim($detail) === '') {
            return $httpStatus !== null ? 'HTTP '.$httpStatus.' (empty body)' : null;
        }

        $trimmed = trim($detail);

        if (self::isHtmlResponse($trimmed)) {
            return $httpStatus !== null
                ? 'HTTP '.$httpStatus.' (HTML error response)'
                : 'HTML error response';
        }

        if (strlen($trimmed) > 500) {
            return substr($trimmed, 0, 500).'...';
        }

        return $trimmed;
    }

    private static function isHtmlResponse(?string $body): bool
    {
        if ($body === null || trim($body) === '') {
            return false;
        }

        $trimmed = trim($body);

        return str_starts_with($trimmed, '<') || str_contains(strtolower($trimmed), '<html');
    }
}
