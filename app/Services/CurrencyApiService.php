<?php

namespace App\Services;

use RuntimeException;

class CurrencyApiService
{
    private const OPEN_ER_API_URL = 'https://open.er-api.com/v6/latest/RUB';
    private const CBR_API_URL = 'https://www.cbr-xml-daily.ru/daily_json.js';

    public function getRubToUsdRate(): float
    {
        $errors = [];

        try {
            $data = $this->requestJson(self::OPEN_ER_API_URL);
            if (isset($data['rates']['USD']) && is_numeric($data['rates']['USD'])) {
                $rate = (float) $data['rates']['USD'];
                if ($rate > 0) {
                    return $rate;
                }
            }
            $errors[] = 'open.er-api returned invalid USD rate.';
        } catch (\Throwable $e) {
            $errors[] = 'open.er-api: ' . $e->getMessage();
        }

        // Fallback: CBR gives RUB per 1 USD, so RUB -> USD = 1 / value.
        try {
            $data = $this->requestJson(self::CBR_API_URL);
            if (isset($data['Valute']['USD']['Value']) && is_numeric($data['Valute']['USD']['Value'])) {
                $rubPerUsd = (float) $data['Valute']['USD']['Value'];
                if ($rubPerUsd > 0) {
                    return 1 / $rubPerUsd;
                }
            }
            $errors[] = 'CBR returned invalid USD value.';
        } catch (\Throwable $e) {
            $errors[] = 'CBR: ' . $e->getMessage();
        }

        throw new RuntimeException('Failed to fetch RUB->USD exchange rate. ' . implode(' | ', $errors));
    }

    private function requestJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: MiddlewareBot/1.0\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException("HTTP request failed for {$url}");
        }

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException("HTTP {$statusCode} for {$url}");
        }

        $data = json_decode($response, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON from {$url}");
        }

        return $data;
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
