<?php

namespace App\Controllers;

use App\Infrastructure\Env;
use App\Infrastructure\Logger;
use App\Services\DealCurrencyService;

class BitrixWebhookController
{
    public function handleDeal(): array
    {
        $expectedToken = Env::get('WEBHOOK_TOKEN', '');
        $incomingToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';

        if ($expectedToken === '') {
            Logger::error('WEBHOOK_TOKEN is not configured.');
            http_response_code(500);
            return ['error' => 'Webhook token is not configured'];
        }

        if (!is_string($incomingToken) || !hash_equals($expectedToken, $incomingToken)) {
            Logger::error('Unauthorized webhook request. Invalid X-Webhook-Token.');
            http_response_code(403);
            return ['error' => 'Unauthorized'];
        }

        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Invalid JSON payload received.');
            http_response_code(400);
            return ['error' => 'Invalid JSON'];
        }

        if (!is_array($data)) {
            Logger::error('JSON payload is not an object.');
            http_response_code(400);
            return ['error' => 'JSON payload must be an object'];
        }

        if (!isset($data['deal_id'], $data['amount'], $data['currency'])) {
            Logger::error('Missing required fields in webhook payload.');
            http_response_code(400);
            return ['error' => 'Missing required fields: deal_id, amount, currency'];
        }

        $service = new DealCurrencyService();

        try {
            Logger::info('Webhook received for deal_id=' . (string) $data['deal_id']);
            $saved = $service->handle($data);

            http_response_code(200);
            return [
                'status' => 'ok',
                'message' => 'Webhook processed successfully',
                'saved' => $saved,
            ];
        } catch (\Throwable $e) {
            Logger::error('Webhook processing failed: ' . $e->getMessage());
            http_response_code(500);
            return [
                'status' => 'error',
                'message' => 'Internal server error',
                'details' => $e->getMessage(),
            ];
        }
    }
}
