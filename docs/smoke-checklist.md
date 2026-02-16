# Smoke Checklist

## Preconditions
- `.env` is configured.
- PostgreSQL is running and reachable.
- Migrations are applied (`composer run migrate`).
- Service is started (`php -S 127.0.0.1:8080 -t public`).

## Checklist
- `GET /health/db` returns `200` and JSON `status=ok`.
- Webhook with valid token/signature/timestamp returns `200` and includes `saved`.
- DB row is created/updated in `public.deals`.
- Webhook with invalid token returns `403`.
- Webhook with invalid JSON returns `400`.
- Webhook with forced tiny budget (`WEBHOOK_PROCESSING_BUDGET_MS=1`) returns `500` and row `status=failed`.
- App with wrong DB password returns `500` with `{"error":"Service configuration error"}`.

## Postman / Bitrix Manual Run
- Use endpoint: `POST /webhooks/bitrix/deal`.
- Send headers: `X-Webhook-Token`, `X-Webhook-Timestamp`, `X-Webhook-Signature`.
- Sign payload as: `<timestamp>.<raw_json_payload>` with HMAC SHA-256 and `WEBHOOK_HMAC_SECRET`.
- Confirm response `status=ok` and DB row in `public.deals`.

## Recorded Successful Run (local, 2026-02-16 21:40:23 UTC)
Environment:
- host: `http://127.0.0.1:8080`
- DB: `middleware`
- table: `public.deals`
- artifact: `storage/logs/smoke-last-run.json`
- source: `storage/logs/app.log` (`WEBHOOK_ACCEPTED`, `DEAL_CONVERTED` for deal `910255001`)

Request:
- `GET /health/db`

Observed response:
```json
{
  "status": "ok",
  "insert_test": "ok_rolled_back"
}
```

Request:
- `POST /webhooks/bitrix/deal` with valid auth headers and payload:
```json
{
  "deal_id": 910255001,
  "amount": "1500.00",
  "currency": "RUB"
}
```

Observed response:
```json
{
  "status": "ok",
  "message": "Webhook processed successfully",
  "saved": {
    "deal_id": 910255001,
    "amount_rub": "1500.00",
    "amount_usd": "15.00",
    "rate": "0.01000000",
    "status": "converted"
  }
}
```

DB verification query:
```sql
SELECT bitrix_deal_id, amount_rub, amount_usd, exchange_rate, status
FROM public.deals
WHERE bitrix_deal_id = 910255001;
```

Observed row:
```text
910255001 | 1500.00 | 15.00 | 0.010000 | converted
```
