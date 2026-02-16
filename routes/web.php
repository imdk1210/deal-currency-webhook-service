<?php

use App\Controllers\BitrixWebhookController;
use App\Controllers\HealthController;

return [
    'POST /webhooks/bitrix/deal' => [BitrixWebhookController::class, 'handleDeal'],
    'GET /health/db' => [HealthController::class, 'db'],
];
