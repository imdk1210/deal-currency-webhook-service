<?php

namespace App\Infrastructure;

class Logger
{
    private const LOG_FILE = __DIR__ . '/../../storage/logs/app.log';
    private const MAX_FILE_SIZE_BYTES = 5242880; // 5 MB
    private const MAX_ROTATED_FILES = 5;

    private static ?string $requestId = null;

    public static function setRequestId(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public static function getRequestId(): ?string
    {
        return self::$requestId;
    }

    public static function info(string $message, array $context = [], string $code = 'INFO'): void
    {
        self::write('INFO', $code, $message, $context);
    }

    public static function error(string $message, array $context = [], string $code = 'ERROR'): void
    {
        self::write('ERROR', $code, $message, $context);
    }

    private static function write(string $level, string $code, string $message, array $context): void
    {
        self::ensureLogDirectory();
        self::rotateIfNeeded();

        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'code' => $code,
            'request_id' => self::$requestId,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        file_put_contents(self::LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function ensureLogDirectory(): void
    {
        $dir = dirname(self::LOG_FILE);
        if (is_dir($dir)) {
            return;
        }

        @mkdir($dir, 0750, true);
    }

    private static function rotateIfNeeded(): void
    {
        if (!is_file(self::LOG_FILE)) {
            return;
        }

        $size = filesize(self::LOG_FILE);
        if ($size === false || $size < self::MAX_FILE_SIZE_BYTES) {
            return;
        }

        $rotatedFile = sprintf(
            '%s/app-%s.log',
            dirname(self::LOG_FILE),
            gmdate('Ymd-His')
        );

        @rename(self::LOG_FILE, $rotatedFile);
        self::pruneRotatedFiles();
    }

    private static function pruneRotatedFiles(): void
    {
        $pattern = dirname(self::LOG_FILE) . '/app-*.log';
        $files = glob($pattern);

        if (!is_array($files) || count($files) <= self::MAX_ROTATED_FILES) {
            return;
        }

        sort($files);
        $filesToDelete = array_slice($files, 0, count($files) - self::MAX_ROTATED_FILES);
        foreach ($filesToDelete as $file) {
            @unlink($file);
        }
    }
}
