# Authentication

Merchant API authentication uses:

1. **OAuth2 client credentials** — `POST /oauth/token` with `client_id` + `client_secret`
2. **Bearer token** — `Authorization: Bearer {access_token}` on all API calls
3. **HMAC signature** — `X-Signature` header, signed with `client_secret`

## Required headers

- `Authorization`
- `X-Signature`
- `X-Idempotency-Key` (payment creation endpoints only)

## Canonical string

```
{HTTP_METHOD}
{REQUEST_PATH}
{base64_sha256_body}
```

Signature: `Base64(HMAC-SHA256(canonical, client_secret))`

Merchant is resolved from the Passport bearer token — no `X-Client-Id` header required.
