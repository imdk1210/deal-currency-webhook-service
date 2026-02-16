<?php

declare(strict_types=1);

namespace Tests;

use App\Services\WebhookSecurityService;
use PHPUnit\Framework\TestCase;

final class WebhookSecurityServiceTest extends TestCase
{
    private WebhookSecurityService $service;

    protected function setUp(): void
    {
        $this->service = new WebhookSecurityService();

        putenv('WEBHOOK_TOKEN=test_token');
        putenv('WEBHOOK_HMAC_SECRET=test_hmac_secret');
        putenv('WEBHOOK_TIMESTAMP_TOLERANCE_SEC=300');
    }

    public function testAuthorizeAcceptsValidRequest(): void
    {
        $payload = '{"deal_id":123,"amount":"100.00","currency":"RUB"}';
        $timestamp = time();
        $signature = $this->service->signPayload($payload, $timestamp, 'test_hmac_secret');

        $result = $this->service->authorize($payload, [
            'HTTP_X_WEBHOOK_TOKEN' => 'test_token',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame($timestamp, $result['timestamp']);
    }

    public function testAuthorizeRejectsExpiredTimestamp(): void
    {
        $payload = '{"deal_id":123}';
        $timestamp = time() - 1000;
        $signature = $this->service->signPayload($payload, $timestamp, 'test_hmac_secret');

        $result = $this->service->authorize($payload, [
            'HTTP_X_WEBHOOK_TOKEN' => 'test_token',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('AUTH_TIMESTAMP_EXPIRED', $result['code']);
    }

    public function testAuthorizeRejectsInvalidSignature(): void
    {
        $payload = '{"deal_id":123}';
        $timestamp = time();

        $result = $this->service->authorize($payload, [
            'HTTP_X_WEBHOOK_TOKEN' => 'test_token',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=invalid',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('AUTH_SIGNATURE_INVALID', $result['code']);
    }
}
