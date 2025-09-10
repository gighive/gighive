<?php declare(strict_types=1);
namespace Production\Api\Infrastructure;

use PDO;
use RuntimeException;

final class Database
{
    /**
     * Create a PDO connection using only system environment variables.
     *
     * Expected env vars (commonly provided via Docker `env_file`, e.g., your .env.mysql):
     * - DB_HOST
     * - MYSQL_DATABASE
     * - MYSQL_USER
     * - MYSQL_PASSWORD
     * - DB_CHARSET (optional; defaults to utf8mb4)
     */
    public static function createFromEnv(): PDO
    {
        $host    = getenv('DB_HOST') ?: 'localhost';
        $db      = getenv('MYSQL_DATABASE') ?: 'music_db';
        $user    = getenv('MYSQL_USER') ?: 'appuser';
        $pass    = getenv('MYSQL_PASSWORD') ?: 'musiclibrary';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $db, $charset);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 0, $e);
        }

        return $pdo;
    }
}

