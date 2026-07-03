# Phase 2 — Authentication

## Purpose

Merchant API authentication using OAuth2 client credentials plus HMAC request signing per the GoDigital specification.

## Flow

1. Merchant calls `POST /oauth/token` with `client_id` and `client_secret`
2. Gateway validates against `merchant_api_clients` and issues a Passport bearer token
3. Payment requests include bearer token plus signed headers

## Required headers (payment APIs)

- `Authorization`, `X-Client-Id`, `X-Request-Id`, `X-Timestamp`, `X-Nonce`, `X-Content-SHA256`, `X-Signature`
- `X-Idempotency-Key` (payment creation only)

## Canonical signature string

```
{METHOD}
{PATH}
{clientId}
{requestId}
{timestamp}
{nonce}
{contentSha256}
```

## Error codes

| Code | Meaning |
|------|---------|
| PGW-1007 | Authentication failed |
| PGW-1008 | Signature validation failed |
| PGW-1009 | Replay protection failed |
| PGW-1004 | Idempotency conflict |

## Testing

```bash
php artisan test --filter=AuthenticationTest
```
