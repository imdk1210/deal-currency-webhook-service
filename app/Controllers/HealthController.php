<?php

namespace App\Controllers;

use App\Infrastructure\Logger;
use PDO;

class HealthController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function db(): array
    {
        $pdo = $this->pdo;

        try {
            $database = $pdo->query('SELECT current_database()')->fetchColumn();
            $schema = $pdo->query('SELECT current_schema()')->fetchColumn();

            $probeDealId = 2000000000 + random_int(1, 1000000);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "
                INSERT INTO public.deals (bitrix_deal_id, amount_rub, status)
                VALUES (:deal_id, :amount_rub, 'healthcheck')
                RETURNING id, bitrix_deal_id, amount_rub, status
                "
            );

            $stmt->execute([
                ':deal_id' => $probeDealId,
                ':amount_rub' => '0.01',
            ]);

            $probe = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$probe) {
                throw new \RuntimeException('Health insert failed: no row returned.');
            }

            $pdo->rollBack();

            http_response_code(200);
            return [
                'status' => 'ok',
                'database' => $database,
                'schema' => $schema,
                'insert_test' => 'ok_rolled_back',
                'probe' => $probe,
                'request_id' => Logger::getRequestId(),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error(
                'Database health check failed.',
                ['error' => $e->getMessage()],
                'HEALTH_DB_FAILED'
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
