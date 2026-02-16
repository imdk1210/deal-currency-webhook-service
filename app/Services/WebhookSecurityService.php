<?php

namespace App\Services;

use App\Infrastructure\Env;

class WebhookSecurityService
{
    public function authorize(string $payload, array $server): array
    {
        $expectedToken = (string) Env::get('WEBHOOK_TOKEN', '');
        $incomingToken = (string) ($server['HTTP_X_WEBHOOK_TOKEN'] ?? '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $incomingToken)) {
            return [
                'ok' => false,
                'code' => 'AUTH_TOKEN_INVALID',
            ];
        }

        $timestampHeader = (string) ($server['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '');
        $signatureHeader = (string) ($server['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');

        if ($timestampHeader === '' || $signatureHeader === '') {
            return [
                'ok' => false,
                'code' => 'AUTH_HEADERS_MISSING',
            ];
        }

        if (!ctype_digit($timestampHeader)) {
            return [
                'ok' => false,
                'code' => 'AUTH_TIMESTAMP_INVALID',
            ];
        }

        $timestamp = (int) $timestampHeader;
        $tolerance = Env::getPositiveInt('WEBHOOK_TIMESTAMP_TOLERANCE_SEC', 300);
        if (abs(time() - $timestamp) > $tolerance) {
            return [
                'ok' => false,
                'code' => 'AUTH_TIMESTAMP_EXPIRED',
                'context' => ['timestamp' => $timestamp],
            ];
        }

        $secret = (string) Env::get('WEBHOOK_HMAC_SECRET', '');
        $providedSignature = $this->normalizeSignature($signatureHeader);
        $computedSignature = hash_hmac('sha256', $timestampHeader . '.' . $payload, $secret);

        if ($providedSignature === '' || !hash_equals($computedSignature, $providedSignature)) {
            return [
                'ok' => false,
                'code' => 'AUTH_SIGNATURE_INVALID',
            ];
        }

        return [
            'ok' => true,
            'timestamp' => $timestamp,
        ];
    }

    public function signPayload(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', (string) $timestamp . '.' . $payload, $secret);
    }

    private function normalizeSignature(string $signature): string
    {
        if (str_contains($signature, '=')) {
            $parts = explode('=', $signature, 2);
            $signature = $parts[1] ?? '';
        }

        return strtolower(trim($signature));
    }
}
