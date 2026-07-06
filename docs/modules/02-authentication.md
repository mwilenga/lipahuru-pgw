# Authentication

## Merchant → Lipahuru (simplified)

Merchants integrate with Lipahuru only. They do **not** call GoDigital and do **not** send GoDigital headers.

1. **OAuth2 client credentials** — `POST /oauth/token` with `client_id` + `client_secret`
2. **Bearer token** — `Authorization: Bearer {access_token}` on all API calls
3. **HMAC signature** — `X-Signature` header, signed with `client_secret`
4. **Idempotency** — `X-Idempotency-Key` on payment creation only

### Required merchant headers

- `Authorization`
- `X-Signature`
- `X-Idempotency-Key` (payment creation endpoints only)

No `X-Client-Id`, `X-Timestamp`, `X-Nonce`, or `X-Content-SHA256` on the merchant side — Lipahuru resolves the merchant from the bearer token.

## Lipahuru → GoDigital (full upstream spec)

When Lipahuru forwards a payment to GoDigital, `GoDigitalHttpClient` adds everything GoDigital requires:

- `Authorization` (GoDigital OAuth token from `.env` credentials)
- `X-Client-Id`, `X-Request-Id`, `X-Timestamp`, `X-Nonce`, `X-Content-SHA256`, `X-Signature`
- `X-Idempotency-Key` on payment POSTs
- `merchantId` in the JSON body (from `GODIGITAL_MERCHANT_ID`)

Configured in `.env` — never exposed to merchants. See `docs/providers/README.md`.

## Canonical string (merchant and GoDigital use the same algorithm)

```
{HTTP_METHOD}
{REQUEST_PATH}
{base64_sha256_body}
```

Signature: `Base64(HMAC-SHA256(canonical, secret))`
