# Deal Currency Webhook Service

REST middleware service for Bitrix deal webhooks:
- stores incoming deals in PostgreSQL;
- converts RUB amount to USD;
- updates processing status (`received`, `converted`, `failed`);
- protects webhook endpoint with token + HMAC + timestamp checks.

## Stack
- PHP 8.2+
- PostgreSQL 14+
- PDO (`pdo_pgsql`)
- cURL
- BCMath
- PHPUnit 13

## Project Structure
- `public/index.php` - composition root and HTTP entrypoint.
- `routes/web.php` - route map.
- `app/Controllers` - HTTP controllers.
- `app/Services` - business logic and integrations.
- `app/Infrastructure` - DB, env, logger, migrations.
- `database/migrations` - schema migrations.
- `tests` - unit and integration tests.

## Environment Variables
Copy `.env.example` to `.env` and set values:

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=middleware
DB_USER=appuser
DB_PASS=secret
WEBHOOK_TOKEN=my_secret_token
WEBHOOK_HMAC_SECRET=my_hmac_secret
WEBHOOK_TIMESTAMP_TOLERANCE_SEC=300
WEBHOOK_PROCESSING_BUDGET_MS=12000
CURRENCY_API_RETRIES=3
CURRENCY_API_BACKOFF_MS=200
CURRENCY_CACHE_TTL_SEC=120
CURRENCY_API_INSECURE_SSL=0
```

## Local Run
1. Install dependencies:
```bash
composer install
```

2. Apply migrations:
```bash
composer run migrate
```

3. Start HTTP server:
```bash
php -S 127.0.0.1:8080 -t public
```

## Endpoints
- `POST /webhooks/bitrix/deal`
- `GET /health/db`

## Webhook Signature
Expected headers:
- `X-Webhook-Token`
- `X-Webhook-Timestamp` (unix timestamp, seconds)
- `X-Webhook-Signature` (`sha256=<hmac>` or plain hex)

HMAC payload format:
`<timestamp>.<raw_json_payload>`

Signature example in PHP:
```php
$payload = '{"deal_id":123,"amount":"1500.00","currency":"RUB"}';
$timestamp = time();
$secret = 'my_hmac_secret';
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
```

Example `curl` request:
```bash
curl -X POST "http://127.0.0.1:8080/webhooks/bitrix/deal" \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Token: my_secret_token" \
  -H "X-Webhook-Timestamp: 1739680000" \
  -H "X-Webhook-Signature: sha256=<signature_here>" \
  -d '{"deal_id":123456,"amount":"1500.00","currency":"RUB"}'
```

## Testing
Run all tests:
```bash
composer run test
```

Run migrations before tests if needed:
```bash
composer run migrate
```

PHP syntax check (Linux/macOS):
```bash
find app bin config public routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

PHP syntax check (PowerShell):
```powershell
Get-ChildItem app,bin,config,public,routes,tests -Recurse -Filter *.php |
ForEach-Object { php -l $_.FullName }
```

## CI
GitHub Actions workflow: `.github/workflows/ci.yml`
- installs dependencies;
- runs migrations;
- runs `php -l`;
- runs PHPUnit tests.

## Smoke Checklist
Detailed checklist and one recorded successful run:
- `docs/smoke-checklist.md`

Run automated smoke scenario:
```bash
composer run smoke
```

Last smoke run artifact is stored in:
- `storage/logs/smoke-last-run.json`
