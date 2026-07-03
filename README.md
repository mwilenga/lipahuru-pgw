# Lipahuru Gateway Core

Laravel 11 payment gateway backend exposing the GoDigital merchant API while routing internally to pluggable provider adapters.

## Stack

- Laravel 11, PHP 8.2+
- MySQL (production) / SQLite (local testing)
- Laravel Passport (merchant API tokens)
- Laravel Sanctum (admin & merchant dashboard login)
- Laravel Horizon (queue monitoring)
- Redis (queues, cache, nonce store)

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan passport:keys
php artisan migrate --seed
php artisan serve
```

Default admin: `admin@lipahuru.test` / `password`

## API surfaces

| Surface | Prefix | Auth |
|---------|--------|------|
| OAuth token | `POST /oauth/token` | client_id + client_secret |
| Merchant API | `/api/v1/*` | Bearer + HMAC signed headers |
| Admin API | `/api/admin/v1/*` | Sanctum admin token |
| Provider webhooks | `POST /internal/webhooks/{provider}` | Provider-specific |

## Architecture

```
Controller → Service → Repository (DAO) → Model
```

Provider adapters implement `PaymentProviderInterface` and are resolved via `ProviderRouter`.

## Key modules

- **Auth** — OAuth tokens, HMAC signing, nonce/replay protection, idempotency
- **Merchants** — Onboarding, wallet hierarchy, provider profiles
- **Payments** — C2B collection, B2C disbursement, transaction state machine
- **Wallets** — Double-entry ledger, fund reservations
- **Webhooks** — Outbound merchant callbacks, inbound provider events
- **Refunds / Settlements / Reports** — Batch processing and aggregations

## Scheduled jobs

Configured in `routes/console.php`:

- Poll pending transactions (every 2 min)
- Retry failed webhooks (every 15 min)
- Reconciliation (hourly)
- Settlement batch (daily 02:00)
- Report aggregation (daily 03:00)
- Nonce purge (daily 04:00)

## Testing

```bash
php artisan test
```

## Documentation

- Merchant integration guide: `docs/MERCHANT_INTEGRATION_GUIDE.md`
- Merchant API spec: `docs/GoDigitalPaymentAPI_260424_180314.pdf`
- Provider adapter notes: `docs/providers/README.md`
- Module docs: `docs/modules/`
- HTTP samples: `http/api.http`
