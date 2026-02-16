<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\BitrixWebhookController;
use App\Controllers\HealthController;
use App\Infrastructure\Database;
use App\Infrastructure\Env;
use App\Infrastructure\Logger;
use App\Services\CurrencyApiService;
use App\Services\DealCurrencyService;
use App\Services\WebhookSecurityService;
use Dotenv\Dotenv;

$displayErrors = ini_set('display_errors', '0');
if ($displayErrors === false) {
    // no-op: continue with default ini setting
}

$projectRoot = dirname(__DIR__);
$controllers = [];

try {
    $dotenv = Dotenv::createImmutable($projectRoot);
    $dotenv->safeLoad();

    Env::require([
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'WEBHOOK_TOKEN',
        'WEBHOOK_HMAC_SECRET',
    ]);

    // Validate optional numeric config early to fail fast.
    Env::getPositiveInt('WEBHOOK_TIMESTAMP_TOLERANCE_SEC', 300);
    Env::getPositiveInt('WEBHOOK_PROCESSING_BUDGET_MS', 12000);
    Env::getPositiveInt('CURRENCY_API_RETRIES', 3);
    Env::getPositiveInt('CURRENCY_API_BACKOFF_MS', 200);
    Env::getPositiveInt('CURRENCY_CACHE_TTL_SEC', 120);

    $pdo = Database::getConnection();
    $currencyApi = new CurrencyApiService();
    $dealService = new DealCurrencyService($pdo, $currencyApi);
    $webhookSecurity = new WebhookSecurityService();

    $controllers = [
        BitrixWebhookController::class => new BitrixWebhookController($dealService, $webhookSecurity),
        HealthController::class => new HealthController($pdo),
    ];
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');

    error_log('Application bootstrap failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Service configuration error']);
    exit;
}

$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
Logger::setRequestId($requestId);

header('X-Request-Id: ' . $requestId);

$routes = require __DIR__ . '/../routes/web.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$key = "{$method} {$path}";

if (!isset($routes[$key])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Route not found',
        'request_id' => Logger::getRequestId(),
    ]);
    exit;
}

[$class, $methodName] = $routes[$key];

if (!isset($controllers[$class])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Controller is not wired in composition root',
        'request_id' => Logger::getRequestId(),
    ]);
    exit;
}

$controller = $controllers[$class];
$response = $controller->$methodName();

header('Content-Type: application/json');
echo json_encode($response);
