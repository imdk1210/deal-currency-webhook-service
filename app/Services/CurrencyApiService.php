<?php

namespace App\Services;

use App\Infrastructure\Env;
use RuntimeException;

class CurrencyApiService
{
    private const OPEN_ER_API_URL = 'https://open.er-api.com/v6/latest/RUB';
    private const CBR_API_URL = 'https://www.cbr-xml-daily.ru/daily_json.js';
    private const CACHE_FILE = __DIR__ . '/../../storage/cache/rub_usd_rate.json';

    private static ?array $inMemoryCache = null;

    public function getRubToUsdRate(): string
    {
        $cachedRate = $this->readCache();
        if ($cachedRate !== null) {
            return $cachedRate;
        }

        $errors = [];
        $budgetMs = Env::getPositiveInt('WEBHOOK_PROCESSING_BUDGET_MS', 12000);
        $deadlineAt = microtime(true) + ($budgetMs / 1000);

        $rate = $this->withRetry(
            'open.er-api',
            function (int $remainingMs): string {
                $data = $this->requestJson(self::OPEN_ER_API_URL, $remainingMs);
                return $this->normalizeRate($data['rates']['USD'] ?? null);
            },
            $errors,
            $deadlineAt
        );

        if ($rate === null) {
            $rate = $this->withRetry(
                'cbr',
                function (int $remainingMs): string {
                    $data = $this->requestJson(self::CBR_API_URL, $remainingMs);
                    $rubPerUsd = $this->normalizeRate($data['Valute']['USD']['Value'] ?? null);
                    return bcdiv('1', $rubPerUsd, 8);
                },
                $errors,
                $deadlineAt
            );
        }

        if ($rate === null) {
            throw new RuntimeException('Failed to fetch RUB->USD exchange rate. ' . implode(' | ', $errors));
        }

        $this->writeCache($rate);

        return $rate;
    }

    private function withRetry(string $provider, callable $operation, array &$errors, float $deadlineAt): ?string
    {
        $maxAttempts = Env::getPositiveInt('CURRENCY_API_RETRIES', 3);
        $baseBackoffMs = Env::getPositiveInt('CURRENCY_API_BACKOFF_MS', 200);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $remainingMs = (int) floor(($deadlineAt - microtime(true)) * 1000);
            if ($remainingMs <= 0) {
                $errors[] = sprintf('%s: processing budget exceeded before attempt %d', $provider, $attempt);
                break;
            }

            try {
                return $operation($remainingMs);
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s attempt %d/%d: %s', $provider, $attempt, $maxAttempts, $e->getMessage());
                if ($attempt < $maxAttempts) {
                    $remainingMs = (int) floor(($deadlineAt - microtime(true)) * 1000);
                    if ($remainingMs <= 0) {
                        break;
                    }

                    $delayMs = min($baseBackoffMs * (2 ** ($attempt - 1)), max(0, $remainingMs - 50));
                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
            }
        }

        return null;
    }

    private function requestJson(string $url, int $remainingMs): array
    {
        $insecureSsl = Env::get('CURRENCY_API_INSECURE_SSL', '0') === '1';

        $timeoutMs = max(200, min(10000, $remainingMs));
        $connectTimeoutMs = max(200, min(5000, $remainingMs));

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_SSL_VERIFYPEER => !$insecureSsl,
            CURLOPT_SSL_VERIFYHOST => $insecureSsl ? 0 : 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: DealCurrencyWebhook/1.0',
            ],
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            throw new RuntimeException('HTTP request failed for ' . $url . ': ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('HTTP ' . $httpCode . ' for ' . $url);
        }

        $data = json_decode($response, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON from ' . $url);
        }

        return $data;
    }

    private function normalizeRate(mixed $value): string
    {
        if ($value === null || (!is_int($value) && !is_float($value) && !is_string($value))) {
            throw new RuntimeException('Rate is missing or invalid.');
        }

        $rate = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $rate)) {
            throw new RuntimeException('Rate is not numeric.');
        }

        if (bccomp($rate, '0', 8) <= 0) {
            throw new RuntimeException('Rate must be greater than zero.');
        }

        return bcadd($rate, '0', 8);
    }

    private function readCache(): ?string
    {
        $ttl = Env::getPositiveInt('CURRENCY_CACHE_TTL_SEC', 120);
        $now = time();

        if (
            self::$inMemoryCache !== null
            && isset(self::$inMemoryCache['rate'], self::$inMemoryCache['expires_at'])
            && (int) self::$inMemoryCache['expires_at'] > $now
        ) {
            return (string) self::$inMemoryCache['rate'];
        }

        if (!is_file(self::CACHE_FILE)) {
            return null;
        }

        $raw = file_get_contents(self::CACHE_FILE);
        if ($raw === false) {
            return null;
        }

        $cache = json_decode($raw, true);
        if (!is_array($cache) || !isset($cache['rate'], $cache['fetched_at'])) {
            return null;
        }

        $fetchedAt = (int) $cache['fetched_at'];
        if (($fetchedAt + $ttl) <= $now) {
            return null;
        }

        $rate = $this->normalizeRate($cache['rate']);
        self::$inMemoryCache = [
            'rate' => $rate,
            'expires_at' => $fetchedAt + $ttl,
        ];

        return $rate;
    }

    private function writeCache(string $rate): void
    {
        $ttl = Env::getPositiveInt('CURRENCY_CACHE_TTL_SEC', 120);
        $now = time();

        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $cache = [
            'rate' => $rate,
            'fetched_at' => $now,
        ];

        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        try {
            $tempFile = self::CACHE_FILE . '.tmp.' . bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $tempFile = self::CACHE_FILE . '.tmp';
        }

        $written = @file_put_contents($tempFile, $json, LOCK_EX);
        if ($written === false) {
            @unlink($tempFile);
            return;
        }

        if (!@rename($tempFile, self::CACHE_FILE)) {
            @unlink($tempFile);
            return;
        }

        self::$inMemoryCache = [
            'rate' => $rate,
            'expires_at' => $now + $ttl,
        ];
    }
}
