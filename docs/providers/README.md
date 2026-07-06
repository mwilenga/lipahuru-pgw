# Upstream Provider API Documentation

## Default provider: GoDigital

Lipahuru routes all mobile money networks (YAS, VODACOM, HALOTEL, AIRTEL) to **GoDigital** by default.

Configure in `.env`:

```
GODIGITAL_BASE_URL=https://your-godigital-host.com
GODIGITAL_OAUTH_PATH=/api/v1/oauth/token
```

GoDigital (e.g. `api.godigital.tz`) uses **`/api/v1/oauth/token`** for tokens and **`/api/v1/payments/...`** for payments. Set `GODIGITAL_BASE_URL` to the host root only (`https://api.godigital.tz`), not `.../api/v1`.

```
GODIGITAL_CLIENT_ID=
GODIGITAL_CLIENT_SECRET=
GODIGITAL_MERCHANT_ID=
GODIGITAL_CALLBACK_URL="${APP_URL}/internal/webhooks/godigital"
GODIGITAL_VERIFY_SSL=true
```

Outbound calls to GoDigital use the **full HMAC header set** required by `api.godigital.tz`:

- `Authorization`, `X-Client-Id`, `X-Request-Id`, `X-Timestamp`, `X-Nonce`, `X-Content-SHA256`, `X-Signature`
- `X-Idempotency-Key` on payment creation

Sign with `GODIGITAL_CLIENT_SECRET` (or `GODIGITAL_SIGNING_SECRET` if provided separately).

Adapter: `app/Providers/Payment/GoDigital/GoDigitalProvider.php`

HTTP samples: `http/api.http`

## Merchant API vs upstream (GoDigital)

| Direction | Who signs | Required headers |
|-----------|-----------|------------------|
| **Merchant → Lipahuru** | Merchant (`client_secret`) | `Authorization`, `X-Signature`, `X-Idempotency-Key` (payments only) |
| **Lipahuru → GoDigital** | Lipahuru (`GODIGITAL_CLIENT_SECRET` in `.env`) | Full HMAC set: `X-Client-Id`, `X-Request-Id`, `X-Timestamp`, `X-Nonce`, `X-Content-SHA256`, `X-Signature`, etc. |

Merchants never talk to GoDigital directly and never need GoDigital credentials. Lipahuru adds `merchantId`, upstream OAuth, timestamps, nonces, and all GoDigital-specific headers automatically in `GoDigitalHttpClient`.

## Additional providers

- Selcom — `docs/providers/selcom/`
- Flutterwave — `docs/providers/flutterwave/`
- NMB — `docs/providers/nmb/`
- CRDB — `docs/providers/crdb/`
