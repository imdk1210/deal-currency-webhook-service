<?php

namespace App\Controllers;

use App\Infrastructure\Database;
use PDO;

class HealthController
{
    public function db(): array
    {
        $pdo = null;

        try {
            $pdo = Database::getConnection();

            $database = $pdo->query('SELECT current_database()')->fetchColumn();
            $schema = $pdo->query('SELECT current_schema()')->fetchColumn();

            $probeDealId = 2000000000 + random_int(1, 1000000);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO public.deals (bitrix_deal_id, amount_rub, status)
                VALUES (:deal_id, :amount_rub, 'healthcheck')
                RETURNING id, bitrix_deal_id, amount_rub, status
            ");

            $stmt->execute([
                ':deal_id' => $probeDealId,
                ':amount_rub' => 0.01,
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
            ];
        } catch (\Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);

            return [
                'status' => 'error',
                'message' => 'Database health check failed',
                'details' => $e->getMessage(),
            ];
        }
    }
}
