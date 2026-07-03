# Upstream Provider API Documentation

## Default provider: GoDigital

Lipahuru routes all mobile money networks (YAS, VODACOM, HALOTEL, AIRTEL) to **GoDigital** by default.

Configure in `.env`:

```
GODIGITAL_BASE_URL=https://your-godigital-uat-host
GODIGITAL_CLIENT_ID=
GODIGITAL_CLIENT_SECRET=
GODIGITAL_MERCHANT_ID=
GODIGITAL_SIGNING_SECRET=
GODIGITAL_CALLBACK_SECRET=
GODIGITAL_CALLBACK_URL="${APP_URL}/internal/webhooks/godigital"
GODIGITAL_VERIFY_SSL=true
```

Adapter: `app/Providers/Payment/GoDigital/GoDigitalProvider.php`

HTTP samples: `http/api.http`

## Additional providers

- Selcom — `docs/providers/selcom/`
- Flutterwave — `docs/providers/flutterwave/`
- NMB — `docs/providers/nmb/`
- CRDB — `docs/providers/crdb/`
