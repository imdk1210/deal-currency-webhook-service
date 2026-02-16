<?php

declare(strict_types=1);

namespace Tests;

use App\Infrastructure\Database;
use App\Infrastructure\MigrationRunner;
use App\Services\CurrencyApiService;
use App\Services\DealCurrencyService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DealCurrencyServiceTest extends TestCase
{
    private const TEST_DEAL_ID = 980000123;

    private PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available: ' . $e->getMessage());
        }

        $runner = new MigrationRunner(
            $this->pdo,
            dirname(__DIR__) . '/database/migrations'
        );
        $runner->migrate();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testIgnoresStaleEventAndKeepsLatestData(): void
    {
        $service = new DealCurrencyService($this->pdo, new FixedRateCurrencyApiService());

        $newTs = time();
        $oldTs = $newTs - 3600;

        $freshResult = $service->handle([
            'deal_id' => self::TEST_DEAL_ID,
            'amount' => '100.00',
            'currency' => 'RUB',
        ], $newTs);

        self::assertSame('converted', $freshResult['status']);

        $staleResult = $service->handle([
            'deal_id' => self::TEST_DEAL_ID,
            'amount' => '250.00',
            'currency' => 'RUB',
        ], $oldTs);

        self::assertSame('ignored_stale_event', $staleResult['status']);

        $stmt = $this->pdo->prepare(
            'SELECT amount_rub, amount_usd, exchange_rate, status FROM public.deals WHERE bitrix_deal_id = :deal_id'
        );
        $stmt->execute([':deal_id' => self::TEST_DEAL_ID]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('100.00', (string) $row['amount_rub']);
        self::assertSame('1.00', (string) $row['amount_usd']);
        self::assertSame('0.010000', (string) $row['exchange_rate']);
        self::assertSame('converted', (string) $row['status']);
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM public.deals WHERE bitrix_deal_id = :deal_id');
        $stmt->execute([':deal_id' => self::TEST_DEAL_ID]);
    }
}

final class FixedRateCurrencyApiService extends CurrencyApiService
{
    public function getRubToUsdRate(): string
    {
        return '0.01000000';
    }
}
