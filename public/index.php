<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Env;

Env::load(__DIR__ . '/../.env');

$routes = require __DIR__ . '/../routes/web.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$key = "$method $path";

if (!isset($routes[$key])) {
    http_response_code(404);
    echo json_encode(['error' => 'Route not found']);
    exit;
}

[$class, $methodName] = $routes[$key];

$controller = new $class();
$response = $controller->$methodName();

header('Content-Type: application/json');
echo json_encode($response);
