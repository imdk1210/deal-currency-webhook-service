<?php

namespace App\Infrastructure;

use RuntimeException;

class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        return $default;
    }

    public static function require(array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            $value = self::get($key);
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            throw new RuntimeException("Environment variable {$key} must be an integer.");
        }

        return (int) $value;
    }

    public static function getPositiveInt(string $key, int $default): int
    {
        $value = self::getInt($key, $default);

        if ($value <= 0) {
            throw new RuntimeException("Environment variable {$key} must be greater than 0.");
        }

        return $value;
    }
}
