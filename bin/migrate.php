<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Database;
use App\Infrastructure\Env;
use App\Infrastructure\MigrationRunner;
use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);

$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->safeLoad();

Env::require([
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASS',
]);

$runner = new MigrationRunner(
    Database::getConnection(),
    $projectRoot . '/database/migrations'
);

$applied = $runner->migrate();

if ($applied === []) {
    echo "No new migrations.\n";
    exit(0);
}

echo "Applied migrations:\n";
foreach ($applied as $version) {
    echo " - {$version}\n";
}
