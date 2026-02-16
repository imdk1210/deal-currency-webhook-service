<?php

namespace App\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PDO driver "pgsql" is not installed. Enable pdo_pgsql in PHP.');
            }

            $host = Env::get('DB_HOST', 'localhost');
            $port = Env::get('DB_PORT', '5432');
            $name = Env::get('DB_NAME', 'middleware');
            $user = Env::get('DB_USER', 'appuser');
            $password = Env::get('DB_PASS', 'secret');

            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

            try {
                self::$connection = new PDO($dsn, $user, $password);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            } catch (PDOException $e) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return self::$connection;
    }
}
