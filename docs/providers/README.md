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

Outbound calls to GoDigital use `GODIGITAL_CLIENT_SECRET` for HMAC signing. Inbound GoDigital callbacks are accepted without signature verification.

Adapter: `app/Providers/Payment/GoDigital/GoDigitalProvider.php`

HTTP samples: `http/api.http`

## Additional providers

- Selcom — `docs/providers/selcom/`
- Flutterwave — `docs/providers/flutterwave/`
- NMB — `docs/providers/nmb/`
- CRDB — `docs/providers/crdb/`
