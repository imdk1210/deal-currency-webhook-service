<?php

namespace App\Controllers;

use App\Infrastructure\Logger;
use App\Services\DealCurrencyService;
use App\Services\WebhookSecurityService;

class BitrixWebhookController
{
    private DealCurrencyService $service;
    private WebhookSecurityService $security;

    public function __construct(DealCurrencyService $service, WebhookSecurityService $security)
    {
        $this->service = $service;
        $this->security = $security;
    }

    public function handleDeal(): array
    {
        $payload = file_get_contents('php://input');
        if ($payload === false) {
            Logger::error('Failed to read request body.', [], 'WEBHOOK_PAYLOAD_READ_FAILED');
            http_response_code(400);

            return [
                'status' => 'error',
                'message' => 'Invalid request payload',
                'request_id' => Logger::getRequestId(),
            ];
        }

        $auth = $this->security->authorize($payload, $_SERVER);
        if (($auth['ok'] ?? false) !== true) {
            Logger::error(
                'Webhook authorization failed.',
                ['code' => $auth['code'] ?? 'AUTH_UNKNOWN'],
                'WEBHOOK_UNAUTHORIZED'
            );

            http_response_code(403);
            return [
                'status' => 'error',
                'message' => 'Unauthorized',
                'request_id' => Logger::getRequestId(),
            ];
        }

        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Invalid JSON payload.', ['json_error' => json_last_error_msg()], 'WEBHOOK_JSON_INVALID');
            http_response_code(400);

            return [
                'status' => 'error',
                'message' => 'Invalid JSON',
                'request_id' => Logger::getRequestId(),
            ];
        }

        if (!is_array($data)) {
            Logger::error('JSON payload must be an object.', [], 'WEBHOOK_JSON_OBJECT_REQUIRED');
            http_response_code(400);

            return [
                'status' => 'error',
                'message' => 'JSON payload must be an object',
                'request_id' => Logger::getRequestId(),
            ];
        }

        if (!isset($data['deal_id'], $data['amount'], $data['currency'])) {
            Logger::error('Missing required fields.', ['payload' => $data], 'WEBHOOK_MISSING_FIELDS');
            http_response_code(400);

            return [
                'status' => 'error',
                'message' => 'Missing required fields: deal_id, amount, currency',
                'request_id' => Logger::getRequestId(),
            ];
        }

        if (!is_numeric((string) $data['amount'])) {
            Logger::error('Amount must be numeric.', ['amount' => $data['amount']], 'WEBHOOK_AMOUNT_INVALID');
            http_response_code(400);

            return [
                'status' => 'error',
                'message' => 'Field "amount" must be numeric',
                'request_id' => Logger::getRequestId(),
            ];
        }

        try {
            Logger::info(
                'Webhook accepted.',
                [
                    'deal_id' => (int) $data['deal_id'],
                    'currency' => (string) $data['currency'],
                ],
                'WEBHOOK_ACCEPTED'
            );

            $saved = $this->service->handle($data, (int) $auth['timestamp']);

            http_response_code(200);
            return [
                'status' => 'ok',
                'message' => 'Webhook processed successfully',
                'saved' => $saved,
                'request_id' => Logger::getRequestId(),
            ];
        } catch (\Throwable $e) {
            Logger::error(
                'Webhook processing failed.',
                [
                    'deal_id' => $data['deal_id'] ?? null,
                    'error' => $e->getMessage(),
                ],
                'WEBHOOK_PROCESSING_FAILED'
            );

            http_response_code(500);
            return [
                'status' => 'error',
                'message' => 'Internal error',
                'request_id' => Logger::getRequestId(),
            ];
        }
    }
}
