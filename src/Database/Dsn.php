<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Driver-aware PDO factory shared by the runtime connection and the installer.
 *
 * Supports `mysql` and `sqlite`. Accepts both the current config shape
 * (`driver` + per-driver block) and the legacy flat MySQL shape
 * (`host`/`port`/`database`/…), so existing config files keep working.
 */
final class Dsn
{
    public const OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * Normalize raw database config into a flat, driver-specific array.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed> {driver: 'mysql', host, port, database, username, password, charset}
     *                              | {driver: 'sqlite', path}
     */
    public static function normalize(array $config): array
    {
        $driver = (string) ($config['driver'] ?? 'mysql');

        if ($driver === 'sqlite') {
            $sqlite = is_array($config['sqlite'] ?? null) ? $config['sqlite'] : $config;
            $path = (string) ($sqlite['path'] ?? '');
            if ($path === '') {
                throw new RuntimeException('sqlite driver requires a database "path".');
            }
            return ['driver' => 'sqlite', 'path' => $path];
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        // New nested `mysql` block, or legacy flat keys.
        $m = is_array($config['mysql'] ?? null) ? $config['mysql'] : $config;
        return [
            'driver' => 'mysql',
            'host' => (string) ($m['host'] ?? 'localhost'),
            'port' => (string) ($m['port'] ?? '3306'),
            'database' => (string) ($m['database'] ?? ''),
            'username' => (string) ($m['username'] ?? ''),
            'password' => (string) ($m['password'] ?? ''),
            'charset' => (string) ($m['charset'] ?? 'utf8mb4'),
        ];
    }

    /** Build the DSN string for a normalized config (no credentials for mysql). */
    public static function string(array $normalized): string
    {
        if ($normalized['driver'] === 'sqlite') {
            return 'sqlite:' . $normalized['path'];
        }
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $normalized['host'],
            $normalized['port'],
            $normalized['database'],
            $normalized['charset']
        );
    }

    /**
     * Open a PDO connection from raw or normalized config and apply
     * driver-appropriate defaults (SQLite pragmas).
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config): PDO
    {
        $c = self::normalize($config);

        if ($c['driver'] === 'sqlite') {
            $dir = dirname($c['path']);
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create sqlite directory: ' . $dir);
            }
            $pdo = new PDO('sqlite:' . $c['path'], null, null, self::OPTIONS);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return $pdo;
        }

        return new PDO(self::string($c), $c['username'], $c['password'], self::OPTIONS);
    }
}
