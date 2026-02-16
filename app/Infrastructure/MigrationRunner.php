<?php

namespace App\Infrastructure;

use PDO;
use RuntimeException;

class MigrationRunner
{
    private PDO $pdo;
    private string $migrationsPath;

    public function __construct(PDO $pdo, string $migrationsPath)
    {
        $this->pdo = $pdo;
        $this->migrationsPath = rtrim($migrationsPath, '/\\');
    }

    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $files = glob($this->migrationsPath . '/*.sql');
        if (!is_array($files)) {
            throw new RuntimeException('Unable to read migrations directory: ' . $this->migrationsPath);
        }

        sort($files, SORT_STRING);

        $applied = $this->getAppliedVersions();
        $appliedNow = [];

        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($applied[$version])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration file: ' . $file);
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO public.schema_migrations (version, applied_at) VALUES (:version, NOW())'
                );
                $stmt->execute([':version' => $version]);

                $this->pdo->commit();
                $appliedNow[] = $version;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new RuntimeException(
                    sprintf('Migration "%s" failed: %s', $version, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        return $appliedNow;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            "
            CREATE TABLE IF NOT EXISTS public.schema_migrations (
                version VARCHAR(255) PRIMARY KEY,
                applied_at TIMESTAMPTZ NOT NULL
            )
            "
        );
    }

    private function getAppliedVersions(): array
    {
        $rows = $this->pdo->query('SELECT version FROM public.schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        $versions = [];
        foreach ($rows as $version) {
            $versions[(string) $version] = true;
        }

        return $versions;
    }
}
