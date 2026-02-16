<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

if (is_file($projectRoot . '/.env')) {
    Dotenv::createImmutable($projectRoot, '.env')->safeLoad();
} else {
    Dotenv::createImmutable($projectRoot, '.env.example')->safeLoad();
}

$defaults = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '5432',
    'DB_NAME' => 'middleware',
    'DB_USER' => 'appuser',
    'DB_PASS' => 'secret',
    'WEBHOOK_TOKEN' => 'my_secret_token',
    'WEBHOOK_HMAC_SECRET' => 'my_hmac_secret',
    'WEBHOOK_TIMESTAMP_TOLERANCE_SEC' => '300',
    'WEBHOOK_PROCESSING_BUDGET_MS' => '12000',
    'CURRENCY_API_RETRIES' => '3',
    'CURRENCY_API_BACKOFF_MS' => '50',
    'CURRENCY_CACHE_TTL_SEC' => '60',
];

foreach ($defaults as $key => $value) {
    if (getenv($key) === false) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}
