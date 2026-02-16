<?php

namespace App\Services;

use App\Infrastructure\Logger;
use App\Support\Money;
use PDO;
use RuntimeException;

class DealCurrencyService
{
    private PDO $pdo;
    private CurrencyApiService $currencyApi;

    public function __construct(PDO $pdo, ?CurrencyApiService $currencyApi = null)
    {
        $this->pdo = $pdo;
        $this->currencyApi = $currencyApi ?? new CurrencyApiService();
    }

    public function handle(array $data, int $eventTimestamp): array
    {
        $deal = $this->upsertReceived($data, $eventTimestamp);

        if (($deal['stale'] ?? false) === true) {
            Logger::info(
                'Ignoring stale webhook event.',
                [
                    'deal_id' => (int) $deal['bitrix_deal_id'],
                    'event_timestamp' => $eventTimestamp,
                    'stored_source_updated_at' => (string) $deal['source_updated_at'],
                ],
                'DEAL_STALE_EVENT'
            );

            return [
                'deal_id' => (int) $deal['bitrix_deal_id'],
                'status' => 'ignored_stale_event',
            ];
        }

        try {
            $rate = $this->currencyApi->getRubToUsdRate();
            $rubMinorUnits = Money::decimalToMinorUnits((string) $deal['amount_rub'], 2);
            $usdMinorUnits = Money::multiplyMinorByRate($rubMinorUnits, $rate, 2);

            $amountUsd = Money::minorUnitsToDecimalString($usdMinorUnits, 2);
            $updated = $this->markConverted((int) $deal['id'], $amountUsd, $rate, $eventTimestamp);

            if (!$updated) {
                Logger::info(
                    'Skipping conversion result because a newer event already updated the deal.',
                    [
                        'deal_id' => (int) $deal['bitrix_deal_id'],
                        'event_timestamp' => $eventTimestamp,
                    ],
                    'DEAL_SUPERSEDED'
                );

                return [
                    'deal_id' => (int) $deal['bitrix_deal_id'],
                    'status' => 'ignored_stale_event',
                ];
            }

            Logger::info(
                'Deal converted successfully.',
                [
                    'deal_id' => (int) $deal['bitrix_deal_id'],
                    'amount_rub' => (string) $deal['amount_rub'],
                    'amount_usd' => $amountUsd,
                    'rate' => $rate,
                ],
                'DEAL_CONVERTED'
            );

            return [
                'deal_id' => (int) $deal['bitrix_deal_id'],
                'amount_rub' => (string) $deal['amount_rub'],
                'amount_usd' => $amountUsd,
                'rate' => $rate,
                'status' => 'converted',
            ];
        } catch (\Throwable $e) {
            $this->markFailed((int) $deal['id'], $eventTimestamp);

            Logger::error(
                'Deal conversion failed.',
                [
                    'deal_id' => (int) $deal['bitrix_deal_id'],
                    'event_timestamp' => $eventTimestamp,
                    'error' => $e->getMessage(),
                ],
                'DEAL_CONVERSION_FAILED'
            );

            throw $e;
        }
    }

    private function upsertReceived(array $data, int $eventTimestamp): array
    {
        $this->pdo->beginTransaction();

        try {
            $rubMinorUnits = Money::decimalToMinorUnits($data['amount'], 2);
            $amountRub = Money::minorUnitsToDecimalString($rubMinorUnits, 2);

            $stmt = $this->pdo->prepare(
                "
                INSERT INTO public.deals (bitrix_deal_id, amount_rub, status, source_updated_at)
                VALUES (:deal_id, :amount_rub, 'received', to_timestamp(:event_ts))
                ON CONFLICT (bitrix_deal_id)
                DO UPDATE SET
                    amount_rub = EXCLUDED.amount_rub,
                    status = 'received',
                    amount_usd = NULL,
                    exchange_rate = NULL,
                    source_updated_at = EXCLUDED.source_updated_at
                WHERE public.deals.source_updated_at IS NULL
                   OR EXCLUDED.source_updated_at >= public.deals.source_updated_at
                RETURNING id, bitrix_deal_id, amount_rub, status, source_updated_at
                "
            );

            $stmt->execute([
                ':deal_id' => (int) $data['deal_id'],
                ':amount_rub' => $amountRub,
                ':event_ts' => $eventTimestamp,
            ]);

            $deal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$deal) {
                $existing = $this->getExistingDeal((int) $data['deal_id']);
                if (!$existing) {
                    throw new RuntimeException('Upsert did not return a deal and existing row was not found.');
                }

                $existing['stale'] = true;
                $this->pdo->commit();

                return $existing;
            }

            $deal['stale'] = false;
            $this->pdo->commit();

            return $deal;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function getExistingDeal(int $dealId): ?array
    {
        $stmt = $this->pdo->prepare(
            "
            SELECT id, bitrix_deal_id, amount_rub, status, source_updated_at
            FROM public.deals
            WHERE bitrix_deal_id = :deal_id
            LIMIT 1
            "
        );

        $stmt->execute([':deal_id' => $dealId]);
        $deal = $stmt->fetch(PDO::FETCH_ASSOC);

        return $deal ?: null;
    }

    private function markConverted(
        int $id,
        string $amountUsd,
        string $rate,
        int $eventTimestamp
    ): bool {
        $stmt = $this->pdo->prepare(
            "
            UPDATE public.deals
            SET amount_usd = :amount_usd,
                exchange_rate = :rate,
                status = 'converted'
            WHERE id = :id
              AND source_updated_at = to_timestamp(:event_ts)
            "
        );

        $stmt->execute([
            ':amount_usd' => $amountUsd,
            ':rate' => $rate,
            ':id' => $id,
            ':event_ts' => $eventTimestamp,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function markFailed(int $id, int $eventTimestamp): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "
                UPDATE public.deals
                SET status = 'failed'
                WHERE id = :id
                  AND source_updated_at = to_timestamp(:event_ts)
                "
            );
            $stmt->execute([
                ':id' => $id,
                ':event_ts' => $eventTimestamp,
            ]);
        } catch (\Throwable $e) {
            // Do not mask the original conversion error.
        }
    }
}
