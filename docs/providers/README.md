# Upstream Provider API Documentation

## Default provider: GoDigital

Lipahuru routes all mobile money networks (YAS, VODACOM, HALOTEL, AIRTEL) to **GoDigital** by default.

Configure in `gateway-core/.env`:

```
GODIGITAL_BASE_URL=https://your-godigital-uat-host
GODIGITAL_CLIENT_ID=
GODIGITAL_CLIENT_SECRET=
GODIGITAL_SIGNING_SECRET=
GODIGITAL_CALLBACK_SECRET=
GODIGITAL_CALLBACK_URL="${APP_URL}/internal/webhooks/godigital"
```

Adapter: `app/Providers/Payment/GoDigital/GoDigitalProvider.php`

HTTP samples: `gateway-core/http/api.http`

## Additional providers

- Selcom — `docs/providers/selcom/`
- Flutterwave — `docs/providers/flutterwave/`
- NMB — `docs/providers/nmb/`
- CRDB — `docs/providers/crdb/`
