# Phase 6 — Payment Processing

## Purpose

C2B push collection and B2C disbursement with async provider submission and normalized transaction states.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/payments/collections/push` | Initiate C2B collection |
| POST | `/api/v1/payments/disbursements` | Initiate B2C disbursement |
| GET | `/api/v1/payments/{transactionId}` | Query transaction status |

## Transaction states

`RECEIVED → AUTHENTICATED → VALIDATED → [FUNDS_RESERVED] → ACKNOWLEDGED → PENDING_FINAL → SUCCESS | FAILED`

## B2C fund reservation

Disbursement reserves funds from the provider disbursement wallet before provider submission. On success, reservation is consumed; on failure, released.

## Testing

```bash
php artisan test --filter=PaymentFlowTest
```
