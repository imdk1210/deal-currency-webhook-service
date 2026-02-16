<?php

namespace App\Infrastructure;

class Logger
{
    private const LOG_FILE = __DIR__ . '/../../storage/app.log';

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        $line = sprintf(
            "[%s] %s: %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            PHP_EOL
        );

        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents(self::LOG_FILE, $line, FILE_APPEND);
    }
}
