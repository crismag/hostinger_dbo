<?php

declare(strict_types=1);

namespace App\Install;

use PDO;
use RuntimeException;

/**
 * Applies versioned, forward-only schema migrations and records them in a
 * `schema_migrations` table, so the gateway's tables can evolve safely across
 * releases. Migrations live in driver-specific directories as numbered `.sql`
 * files (e.g. `0001_audit_ip_index.sql`) and run once, in filename order.
 *
 * The base install schema is the v1 baseline; migrations are changes on top of
 * it. Each migration runs inside a transaction where the driver supports
 * transactional DDL (SQLite does; MySQL auto-commits DDL).
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationsDir,
    ) {
    }

    private function ensureTable(): void
    {
        $sql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            : 'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->db->exec($sql);
    }

    /** @return list<string> migration versions already applied */
    public function applied(): array
    {
        $this->ensureTable();
        return array_map('strval', $this->db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> all migration versions available on disk, in order */
    public function available(): array
    {
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        $versions = array_map(static fn (string $f): string => basename($f, '.sql'), $files);
        sort($versions);
        return $versions;
    }

    /** @return list<string> available but not yet applied, in order */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * Apply all pending migrations in order.
     *
     * @return list<string> versions applied during this run
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = [];
        $record = $this->db->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        foreach ($this->pending() as $version) {
            $path = $this->migrationsDir . '/' . $version . '.sql';
            $sql = (string) file_get_contents($path);
            if ($sql === '') {
                throw new RuntimeException("Empty migration: $version");
            }
            foreach ($this->splitStatements($sql) as $statement) {
                $this->db->exec($statement);
            }
            $record->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $chunk) {
            if (trim($chunk) !== '') {
                $statements[] = trim($chunk);
            }
        }
        return $statements;
    }
}
