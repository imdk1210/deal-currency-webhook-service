<?php

declare(strict_types=1);

namespace Tests;

use App\Infrastructure\Database;
use App\Infrastructure\MigrationRunner;
use App\Services\WebhookSecurityService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpTestServer;

final class ControllersIntegrationTest extends TestCase
{
    private const WEBHOOK_PATH = '/webhooks/bitrix/deal';
    private const HEALTH_PATH = '/health/db';

    private string $projectRoot;
    private string $cacheFile;
    private bool $cacheExisted = false;
    private ?string $cacheSnapshot = null;

    private ?PDO $pdo = null;
    private ?HttpTestServer $server = null;

    /** @var list<int> */
    private array $createdDealIds = [];

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
        $this->cacheFile = $this->projectRoot . '/storage/cache/rub_usd_rate.json';

        $this->cacheExisted = is_file($this->cacheFile);
        if ($this->cacheExisted) {
            $content = file_get_contents($this->cacheFile);
            $this->cacheSnapshot = $content === false ? null : $content;
        }

        try {
            $this->pdo = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available: ' . $e->getMessage());
        }

        $runner = new MigrationRunner(
            $this->pdo,
            $this->projectRoot . '/database/migrations'
        );
        $runner->migrate();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->restoreCache();
        $this->cleanupDeals();
    }

    public function testHealthDbReturnsOk(): void
    {
        $this->startServer();

        $response = $this->request('GET', self::HEALTH_PATH);

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['json']);
        self::assertSame('ok', $response['json']['status'] ?? null);
        self::assertSame('ok_rolled_back', $response['json']['insert_test'] ?? null);
        self::assertArrayHasKey('request_id', $response['json']);
    }

    public function testWebhookRejectsUnauthorizedRequest(): void
    {
        $this->startServer();

        $payload = json_encode([
            'deal_id' => 910000001,
            'amount' => '100.00',
            'currency' => 'RUB',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            self::fail('Failed to build webhook payload.');
        }

        $headers = $this->buildWebhookHeaders($payload, [
            'X-Webhook-Token' => 'wrong_token',
        ]);

        $response = $this->request('POST', self::WEBHOOK_PATH, $payload, $headers);

        self::assertSame(403, $response['status']);
        self::assertIsArray($response['json']);
        self::assertSame('error', $response['json']['status'] ?? null);
        self::assertSame('Unauthorized', $response['json']['message'] ?? null);
    }

    public function testWebhookRejectsInvalidJsonPayload(): void
    {
        $this->startServer();

        $payload = '{"deal_id": 910000002, "amount": "100.00"';
        $headers = $this->buildWebhookHeaders($payload);

        $response = $this->request('POST', self::WEBHOOK_PATH, $payload, $headers);

        self::assertSame(400, $response['status']);
        self::assertIsArray($response['json']);
        self::assertSame('error', $response['json']['status'] ?? null);
        self::assertSame('Invalid JSON', $response['json']['message'] ?? null);
    }

    public function testWebhookReturnsInternalErrorWhenCurrencyFetchFails(): void
    {
        $this->removeCache();
        $this->startServer([
            'WEBHOOK_PROCESSING_BUDGET_MS' => '1',
            'CURRENCY_API_RETRIES' => '1',
            'CURRENCY_API_BACKOFF_MS' => '1',
            'CURRENCY_CACHE_TTL_SEC' => '1',
        ]);

        $dealId = random_int(910100000, 910199999);
        $this->createdDealIds[] = $dealId;

        $payload = json_encode([
            'deal_id' => $dealId,
            'amount' => '100.00',
            'currency' => 'RUB',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            self::fail('Failed to build webhook payload.');
        }

        $headers = $this->buildWebhookHeaders($payload);
        $response = $this->request('POST', self::WEBHOOK_PATH, $payload, $headers);

        self::assertSame(500, $response['status']);
        self::assertIsArray($response['json']);
        self::assertSame('error', $response['json']['status'] ?? null);
        self::assertSame('Internal error', $response['json']['message'] ?? null);

        $stmt = $this->pdo->prepare(
            'SELECT status FROM public.deals WHERE bitrix_deal_id = :deal_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':deal_id' => $dealId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('failed', (string) $row['status']);
    }

    public function testWebhookProcessesSuccessfullyUsingCachedRate(): void
    {
        $this->writeCacheRate('0.01000000');
        $this->startServer();

        $dealId = random_int(910200000, 910299999);
        $this->createdDealIds[] = $dealId;

        $payload = json_encode([
            'deal_id' => $dealId,
            'amount' => '1500.00',
            'currency' => 'RUB',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            self::fail('Failed to build webhook payload.');
        }

        $headers = $this->buildWebhookHeaders($payload);
        $response = $this->request('POST', self::WEBHOOK_PATH, $payload, $headers);

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['json']);
        self::assertSame('ok', $response['json']['status'] ?? null);
        self::assertSame('converted', $response['json']['saved']['status'] ?? null);
        self::assertSame('15.00', $response['json']['saved']['amount_usd'] ?? null);
    }

    public function testApplicationReturnsConfigurationErrorWhenDatabaseConnectionFails(): void
    {
        $this->stopServer();
        $this->startServer([
            'DB_PASS' => 'definitely_wrong_password',
        ]);

        $response = $this->request('GET', self::HEALTH_PATH);

        self::assertSame(500, $response['status']);
        self::assertIsArray($response['json']);
        self::assertSame('Service configuration error', $response['json']['error'] ?? null);
    }

    private function startServer(array $overrides = []): void
    {
        $this->stopServer();

        $this->server = new HttpTestServer($this->projectRoot);
        $this->server->start($this->buildServerEnv($overrides));
    }

    private function stopServer(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
    }

    private function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        if ($this->server === null) {
            self::fail('HTTP test server is not started.');
        }

        return $this->server->request($method, $path, $body, $headers);
    }

    private function buildServerEnv(array $overrides): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }

        $defaults = [
            'DB_HOST' => (string) (getenv('DB_HOST') ?: 'localhost'),
            'DB_PORT' => (string) (getenv('DB_PORT') ?: '5432'),
            'DB_NAME' => (string) (getenv('DB_NAME') ?: 'middleware'),
            'DB_USER' => (string) (getenv('DB_USER') ?: 'appuser'),
            'DB_PASS' => (string) (getenv('DB_PASS') ?: 'secret'),
            'WEBHOOK_TOKEN' => (string) (getenv('WEBHOOK_TOKEN') ?: 'my_secret_token'),
            'WEBHOOK_HMAC_SECRET' => (string) (getenv('WEBHOOK_HMAC_SECRET') ?: 'my_hmac_secret'),
            'WEBHOOK_TIMESTAMP_TOLERANCE_SEC' => (string) (getenv('WEBHOOK_TIMESTAMP_TOLERANCE_SEC') ?: '300'),
            'WEBHOOK_PROCESSING_BUDGET_MS' => (string) (getenv('WEBHOOK_PROCESSING_BUDGET_MS') ?: '12000'),
            'CURRENCY_API_RETRIES' => (string) (getenv('CURRENCY_API_RETRIES') ?: '3'),
            'CURRENCY_API_BACKOFF_MS' => (string) (getenv('CURRENCY_API_BACKOFF_MS') ?: '200'),
            'CURRENCY_CACHE_TTL_SEC' => (string) (getenv('CURRENCY_CACHE_TTL_SEC') ?: '120'),
            'CURRENCY_API_INSECURE_SSL' => (string) (getenv('CURRENCY_API_INSECURE_SSL') ?: '0'),
        ];

        $merged = array_merge($env, $defaults, $overrides);

        foreach ($merged as $key => $value) {
            if (!is_scalar($value)) {
                unset($merged[$key]);
                continue;
            }

            $merged[$key] = (string) $value;
        }

        return $merged;
    }

    private function buildWebhookHeaders(string $payload, array $overrides = []): array
    {
        $timestamp = time();
        $secret = (string) (getenv('WEBHOOK_HMAC_SECRET') ?: 'my_hmac_secret');

        $security = new WebhookSecurityService();
        $signature = $security->signPayload($payload, $timestamp, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Token' => (string) (getenv('WEBHOOK_TOKEN') ?: 'my_secret_token'),
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => $signature,
        ];

        return array_merge($headers, $overrides);
    }

    private function writeCacheRate(string $rate): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $payload = json_encode([
            'rate' => $rate,
            'fetched_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            self::fail('Failed to build cache payload.');
        }

        $result = file_put_contents($this->cacheFile, $payload, LOCK_EX);
        if ($result === false) {
            self::fail('Failed to write currency cache file.');
        }
    }

    private function removeCache(): void
    {
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function restoreCache(): void
    {
        if ($this->cacheExisted) {
            if ($this->cacheSnapshot !== null) {
                file_put_contents($this->cacheFile, $this->cacheSnapshot, LOCK_EX);
            }

            return;
        }

        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function cleanupDeals(): void
    {
        if ($this->pdo === null || $this->createdDealIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM public.deals WHERE bitrix_deal_id = :deal_id');
        foreach (array_unique($this->createdDealIds) as $dealId) {
            $stmt->execute([':deal_id' => $dealId]);
        }

        $this->createdDealIds = [];
    }
}
