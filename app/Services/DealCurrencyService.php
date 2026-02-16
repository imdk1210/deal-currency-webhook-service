<?php

namespace App\Services;

use App\Infrastructure\Database;
use App\Infrastructure\Logger;
use PDO;
use RuntimeException;

class DealCurrencyService
{
    public function handle(array $data): array
    {
        $pdo = Database::getConnection();
        $currencyApi = new CurrencyApiService();

        $deal = $this->upsertReceived($pdo, $data);

        try {
            $rate = $currencyApi->getRubToUsdRate();
            $amountUsd = round(((float) $deal['amount_rub']) * $rate, 2);

            $this->markConverted($pdo, (int) $deal['id'], $amountUsd, $rate);

            Logger::info(
                sprintf(
                    'Deal %d converted: RUB=%s USD=%s rate=%s',
                    (int) $deal['bitrix_deal_id'],
                    (string) $deal['amount_rub'],
                    (string) $amountUsd,
                    (string) $rate
                )
            );

            return [
                'deal_id' => (int) $deal['bitrix_deal_id'],
                'amount_rub' => (float) $deal['amount_rub'],
                'amount_usd' => $amountUsd,
                'rate' => $rate,
                'status' => 'converted',
            ];
        } catch (\Throwable $e) {
            $this->markFailed($pdo, (int) $deal['id']);
            Logger::error('Deal conversion failed for bitrix_deal_id=' . (string) $deal['bitrix_deal_id'] . ': ' . $e->getMessage());
            throw $e;
        }
    }

    private function upsertReceived(PDO $pdo, array $data): array
    {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("\n                INSERT INTO public.deals (bitrix_deal_id, amount_rub, status)\n                VALUES (:deal_id, :amount_rub, 'received')\n                ON CONFLICT (bitrix_deal_id)\n                DO UPDATE SET\n                    amount_rub = EXCLUDED.amount_rub,\n                    status = 'received',\n                    amount_usd = NULL,\n                    exchange_rate = NULL\n                RETURNING id, bitrix_deal_id, amount_rub, status\n            ");

            $stmt->execute([
                ':deal_id' => $data['deal_id'],
                ':amount_rub' => $data['amount'],
            ]);

            $deal = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$deal) {
                throw new RuntimeException('Upsert failed.');
            }

            $pdo->commit();

            return $deal;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function markConverted(PDO $pdo, int $id, float $amountUsd, float $rate): void
    {
        $stmt = $pdo->prepare("\n            UPDATE public.deals\n            SET amount_usd = :amount_usd,\n                exchange_rate = :rate,\n                status = 'converted'\n            WHERE id = :id\n        ");

        $stmt->execute([
            ':amount_usd' => $amountUsd,
            ':rate' => $rate,
            ':id' => $id,
        ]);
    }

    private function markFailed(PDO $pdo, int $id): void
    {
        try {
            $stmt = $pdo->prepare("\n                UPDATE public.deals\n                SET status = 'failed'\n                WHERE id = :id\n            ");
            $stmt->execute([':id' => $id]);
        } catch (\Throwable $e) {
            // Do not mask the original conversion error.
        }
    }
}
