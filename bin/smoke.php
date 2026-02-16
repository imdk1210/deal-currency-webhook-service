<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Database;
use App\Infrastructure\Env;
use App\Infrastructure\MigrationRunner;
use Dotenv\Dotenv;

final class LocalHttpServer
{
    private string $projectRoot;
    private string $host;
    private int $port;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(string $projectRoot, string $host = '127.0.0.1', int $port = 8080)
    {
        $this->projectRoot = $projectRoot;
        $this->host = $host;
        $this->port = $port;
    }

    public function start(): void
    {
        $command = sprintf(
            '%s -S %s:%d -t public',
            escapeshellarg(PHP_BINARY),
            $this->host,
            $this->port
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start local HTTP server.');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            stream_set_blocking($this->pipes[1], false);
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            stream_set_blocking($this->pipes[2], false);
        }

        $this->waitUntilReady();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            @proc_close($this->process);
        }

        $this->process = null;
        $this->pipes = [];
    }

    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'ignore_errors' => true,
                'timeout' => 20,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
            ],
        ]);

        $responseBody = @file_get_contents($this->baseUrl() . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];

        $json = json_decode(is_string($responseBody) ? $responseBody : '', true);

        return [
            'status' => $this->extractStatusCode($responseHeaders),
            'body' => is_string($responseBody) ? $responseBody : '',
            'json' => is_array($json) ? $json : null,
        ];
    }

    private function baseUrl(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    private function waitUntilReady(): void
    {
        $url = $this->baseUrl() . '/__ready__';

        for ($attempt = 0; $attempt < 80; $attempt++) {
            if (!$this->isRunning()) {
                throw new RuntimeException('Local HTTP server exited early.');
            }

            @file_get_contents($url);
            $responseHeaders = $http_response_header ?? [];
            if ($this->extractStatusCode($responseHeaders) > 0) {
                return;
            }

            usleep(100000);
        }

        throw new RuntimeException('Local HTTP server did not become ready.');
    }

    private function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);
        return (bool) ($status['running'] ?? false);
    }

    private function extractStatusCode(array $headers): int
    {
        if (!isset($headers[0])) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}

$projectRoot = dirname(__DIR__);

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

$pdo = Database::getConnection();
$runner = new MigrationRunner($pdo, $projectRoot . '/database/migrations');
$runner->migrate();

$dealId = 910255001;
$cacheFile = $projectRoot . '/storage/cache/rub_usd_rate.json';
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

$cachePayload = json_encode([
    'rate' => '0.01000000',
    'fetched_at' => time(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!is_string($cachePayload)) {
    throw new RuntimeException('Failed to build cache payload.');
}

$cacheWrite = file_put_contents($cacheFile, $cachePayload, LOCK_EX);
if ($cacheWrite === false) {
    throw new RuntimeException('Failed to write cache file for smoke run.');
}

$server = new LocalHttpServer($projectRoot);
$server->start();

try {
    $health = $server->request('GET', '/health/db');

    $payload = json_encode([
        'deal_id' => $dealId,
        'amount' => '1500.00',
        'currency' => 'RUB',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        throw new RuntimeException('Failed to build webhook payload.');
    }

    $timestamp = time();
    $signature = hash_hmac(
        'sha256',
        $timestamp . '.' . $payload,
        (string) Env::get('WEBHOOK_HMAC_SECRET', '')
    );

    $webhook = $server->request(
        'POST',
        '/webhooks/bitrix/deal',
        $payload,
        [
            'Content-Type' => 'application/json',
            'X-Webhook-Token' => (string) Env::get('WEBHOOK_TOKEN', ''),
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => $signature,
        ]
    );

    $stmt = $pdo->prepare(
        'SELECT bitrix_deal_id, amount_rub, amount_usd, exchange_rate, status
         FROM public.deals
         WHERE bitrix_deal_id = :deal_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':deal_id' => $dealId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $result = [
        'date_utc' => gmdate('Y-m-d H:i:s'),
        'health' => $health,
        'webhook' => $webhook,
        'db_row' => $row ?: null,
    ];

    $encoded = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('Failed to encode smoke result.');
    }

    $outputFile = $projectRoot . '/storage/logs/smoke-last-run.json';
    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0750, true);
    }

    $outputWrite = file_put_contents($outputFile, $encoded . PHP_EOL, LOCK_EX);
    if ($outputWrite === false) {
        throw new RuntimeException('Failed to write smoke result artifact.');
    }
    echo $encoded . PHP_EOL;
} finally {
    $server->stop();
}
