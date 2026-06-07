<?php

/**
 * @file Installer.php
 *
 * Implements shared installation, schema-loading, client-provisioning, config-writing, and permission-hardening operations.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Install;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Shared installer core used by both the CLI wizard (bin/install.php) and the
 * web installer (public/install.php). Pure logic — it never reads the existing
 * gateway config and builds its own PDO connection so it can run before the
 * gateway is configured.
 */
final class Installer
{
    public const ACTIONS = ['select', 'insert', 'update', 'delete'];

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function configDir(): string
    {
        return $this->root . '/config';
    }

    public function databaseConfigPath(): string
    {
        return $this->configDir() . '/database.php';
    }

    public function securityConfigPath(): string
    {
        return $this->configDir() . '/security.php';
    }

    public function storageDir(): string
    {
        return $this->root . '/storage';
    }

    public function lockPath(): string
    {
        return $this->storageDir() . '/.install-lock';
    }

    public function rateLimitDir(): string
    {
        return $this->storageDir() . '/ratelimit';
    }

    /** The installer is considered done once the security config exists or a lock is present. */
    public function isInstalled(): bool
    {
        return is_file($this->lockPath()) || is_file($this->securityConfigPath());
    }

    /**
     * Environment checks. Returns a list of ['name','ok','detail','fatal'].
     *
     * @return list<array{name:string,ok:bool,detail:string,fatal:bool}>
     */
    public function preflight(): array
    {
        $checks = [];
        $php = PHP_VERSION;
        $checks[] = [
            'name' => 'PHP >= 8.1',
            'ok' => PHP_VERSION_ID >= 80100,
            'detail' => $php,
            'fatal' => true,
        ];
        foreach (['mbstring', 'json'] as $ext) {
            $checks[] = [
                'name' => "ext-$ext",
                'ok' => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'loaded' : 'missing',
                'fatal' => true,
            ];
        }
        $drivers = array_values(array_filter(['pdo_mysql', 'pdo_sqlite'], 'extension_loaded'));
        $checks[] = [
            'name' => 'PDO driver',
            'ok' => $drivers !== [],
            'detail' => $drivers === [] ? 'need pdo_mysql or pdo_sqlite' : implode(', ', $drivers),
            'fatal' => true,
        ];
        $checks[] = [
            'name' => 'CSPRNG (random_bytes)',
            'ok' => function_exists('random_bytes'),
            'detail' => function_exists('random_bytes') ? 'available' : 'missing',
            'fatal' => true,
        ];
        $configWritable = is_dir($this->configDir())
            ? is_writable($this->configDir())
            : is_writable($this->root);
        $checks[] = [
            'name' => 'config/ writable',
            'ok' => $configWritable,
            'detail' => $this->configDir(),
            'fatal' => true,
        ];
        $checks[] = [
            'name' => 'docroot is public/',
            'ok' => is_file($this->root . '/public/index.php'),
            'detail' => 'serve only the public/ directory',
            'fatal' => false,
        ];
        return $checks;
    }

    public function preflightPasses(): bool
    {
        foreach ($this->preflight() as $check) {
            if ($check['fatal'] && !$check['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Open a PDO connection. When $createDatabase is true, connect without a
     * database, CREATE IF NOT EXISTS, then reconnect into it.
     *
     * @param array{host:string,port:string,database:string,username:string,password:string,charset:string} $db
     */
    public function connect(array $db, bool $createDatabase = false): PDO
    {
        require_once __DIR__ . '/../Database/Dsn.php';
        $c = \App\Database\Dsn::normalize($db);

        // MySQL only: optionally create the database before connecting into it.
        // (SQLite has no CREATE DATABASE — the file is the database, created on connect.)
        if ($c['driver'] === 'mysql' && $createDatabase) {
            $serverDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $c['host'], $c['port'], $c['charset']);
            $pdo = new PDO($serverDsn, $c['username'], $c['password'], \App\Database\Dsn::OPTIONS);
            $quoted = '`' . str_replace('`', '``', $c['database']) . '`';
            $pdo->exec("CREATE DATABASE IF NOT EXISTS $quoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        return \App\Database\Dsn::connect($c);
    }

    /**
     * Verify a database connection. Returns ['ok'=>bool,'error'=>?string,'tables'=>list<string>].
     *
     * @param array{host:string,port:string,database:string,username:string,password:string,charset:string} $db
     * @return array{ok:bool,error:?string,tables:list<string>}
     */
    public function testDatabase(array $db, bool $createDatabase = false): array
    {
        try {
            $pdo = $this->connect($db, $createDatabase);
            return ['ok' => true, 'error' => null, 'tables' => $this->existingTables($pdo)];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'tables' => []];
        }
    }

    /** @return list<string> */
    public function existingTables(PDO $pdo): array
    {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            : 'SHOW TABLES';
        $tables = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }
        return $tables;
    }

    /** Directory holding the schema files for this connection's driver. */
    private function schemaDir(PDO $pdo): string
    {
        return $this->root . '/schema' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '/sqlite' : '');
    }

    /**
     * Load schema files idempotently. Security tables run only if api_clients is
     * absent; example objects only if the projects table is absent.
     *
     * @return list<array{file:string,ran:bool,note:string}>
     */
    public function loadSchema(PDO $pdo, bool $includeExamples): array
    {
        $results = [];
        $tables = $this->existingTables($pdo);
        $dir = $this->schemaDir($pdo);

        if (in_array('api_clients', $tables, true)) {
            $results[] = ['file' => 'security_tables.sql', 'ran' => false, 'note' => 'already present, skipped'];
        } else {
            $this->runSqlFile($pdo, $dir . '/security_tables.sql');
            $results[] = ['file' => 'security_tables.sql', 'ran' => true, 'note' => 'created security/registry tables'];
        }

        if ($includeExamples) {
            if (in_array('projects', $tables, true)) {
                $results[] = ['file' => 'example_objects.sql', 'ran' => false, 'note' => 'already present, skipped'];
            } else {
                $this->runSqlFile($pdo, $dir . '/example_objects.sql');
                $results[] = ['file' => 'example_objects.sql', 'ran' => true, 'note' => 'created example objects + registry rows'];
            }
        }

        // Apply forward migrations beyond the baseline schema.
        require_once __DIR__ . '/MigrationRunner.php';
        $migrationsDir = $dir . '/migrations';
        if (is_dir($migrationsDir)) {
            foreach ((new MigrationRunner($pdo, $migrationsDir))->migrate() as $version) {
                $results[] = ['file' => "migration:$version", 'ran' => true, 'note' => 'applied'];
            }
        }

        return $results;
    }

    private function runSqlFile(PDO $pdo, string $path): void
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Schema file not readable: $path");
        }
        foreach ($this->splitStatements((string) file_get_contents($path)) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Split a .sql file into individual statements. The bundled schema files use
     * no stored routines, delimiters, or semicolons inside string literals, so a
     * comment-strip + semicolon split is sufficient and safe.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }
        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }
        return $statements;
    }

    /** @return list<string> registered entity names */
    public function registeredEntities(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT entity_name FROM api_entities ORDER BY entity_name')
                ->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn (array $r): string => (string) $r[0], $rows);
    }

    /** Run a single SQL file (e.g. an application's object-table schema). */
    public function importSql(PDO $pdo, string $path): void
    {
        $this->runSqlFile($pdo, $path);
    }

    /**
     * Upsert entities into api_entities from an application registry. Every
     * identifier is validated before it can reach a future SQL statement.
     *
     * @param array<string, array{table?:string,primary_key?:string,policy?:array<string,mixed>}> $entities keyed by entity_name
     * @return list<array{entity:string,action:string}>
     */
    public function registerEntities(PDO $pdo, array $entities): array
    {
        $results = [];
        $find = $pdo->prepare('SELECT id FROM api_entities WHERE entity_name = ?');
        $insert = $pdo->prepare('INSERT INTO api_entities (entity_name, table_name, primary_key_name, enabled, schema_json) VALUES (:e, :t, :pk, 1, :s)');
        $update = $pdo->prepare('UPDATE api_entities SET table_name = :t, primary_key_name = :pk, enabled = 1, schema_json = :s WHERE entity_name = :e');
        foreach ($entities as $name => $def) {
            $name = (string) $name;
            $table = (string) ($def['table'] ?? $name);
            $pk = (string) ($def['primary_key'] ?? 'id');
            $policy = (array) ($def['policy'] ?? []);
            $this->assertIdentifier($name);
            $this->assertIdentifier($table);
            $this->assertIdentifier($pk);
            foreach ($policy as $list) {
                foreach ((array) $list as $identifier) {
                    $this->assertIdentifier((string) $identifier);
                }
            }
            $params = [':e' => $name, ':t' => $table, ':pk' => $pk, ':s' => json_encode($policy, JSON_THROW_ON_ERROR)];
            $find->execute([$name]);
            $exists = $find->fetchColumn() !== false;
            ($exists ? $update : $insert)->execute($params);
            $results[] = ['entity' => $name, 'action' => $exists ? 'updated' : 'registered'];
        }
        return $results;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Invalid SQL identifier in app registry: ' . $identifier);
        }
    }

    public function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Load the current security config as an array (empty if not yet written).
     * Used by the admin tool for incremental edits that must not clobber the
     * rest of the file.
     *
     * @return array<string, mixed>
     */
    public function loadSecurity(): array
    {
        return is_readable($this->securityConfigPath()) ? (array) (require $this->securityConfigPath()) : [];
    }

    /**
     * Persist a full security config array back to config/security.php (0600).
     *
     * @param array<string, mixed> $config
     */
    public function saveSecurity(array $config): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Updated by the admin tool. client_secrets hold HMAC keys; treat this file\n"
            . "// as a credential and keep it 0600, outside the web root.\n\n"
            . 'return ';
        $this->atomicWrite($this->securityConfigPath(), $header . $this->exportArray($config) . ";\n", 0o600);
    }

    /**
     * Create the first API client and its per-entity permissions. The HMAC
     * secret lives in config/security.php, never in the database, so secret_hash
     * is a non-sensitive marker.
     *
     * @param list<string> $entities
     * @param list<string> $actions  subset of self::ACTIONS
     * @return array{client_id:string,secret:string,db_id:int}
     */
    public function createClient(
        PDO $pdo,
        string $clientId,
        string $clientName,
        array $entities,
        array $actions,
        int $maxRows = 100
    ): array {
        if (!preg_match('/^[A-Za-z0-9._-]{3,128}$/', $clientId)) {
            throw new RuntimeException('client_id must be 3-128 chars of [A-Za-z0-9._-].');
        }
        $actions = array_values(array_intersect(self::ACTIONS, $actions));
        if ($actions === []) {
            $actions = ['select'];
        }
        $secret = $this->generateSecret();

        $existing = $pdo->prepare('SELECT id FROM api_clients WHERE client_id = ?');
        $existing->execute([$clientId]);
        $dbId = $existing->fetchColumn();

        if ($dbId === false) {
            $insert = $pdo->prepare(
                'INSERT INTO api_clients (client_id, client_name, status, secret_hash, description)'
                . " VALUES (?, ?, 'active', ?, ?)"
            );
            $insert->execute([
                $clientId,
                $clientName !== '' ? $clientName : $clientId,
                'managed-in-config/security.php',
                'Created by installer',
            ]);
            $dbId = (int) $pdo->lastInsertId();
        } else {
            $dbId = (int) $dbId;
        }

        // Portable upsert (works on both MySQL and SQLite): select, then update or insert.
        $find = $pdo->prepare('SELECT id FROM api_client_permissions WHERE client_id = ? AND entity_name = ?');
        $insertPerm = $pdo->prepare(
            'INSERT INTO api_client_permissions'
            . ' (client_id, entity_name, can_select, can_insert, can_update, can_delete, max_rows_per_select)'
            . ' VALUES (:cid, :entity, :s, :i, :u, :d, :max)'
        );
        $updatePerm = $pdo->prepare(
            'UPDATE api_client_permissions SET can_select = :s, can_insert = :i, can_update = :u,'
            . ' can_delete = :d, max_rows_per_select = :max WHERE client_id = :cid AND entity_name = :entity'
        );
        foreach ($entities as $entity) {
            $params = [
                ':cid' => $dbId,
                ':entity' => $entity,
                ':s' => in_array('select', $actions, true) ? 1 : 0,
                ':i' => in_array('insert', $actions, true) ? 1 : 0,
                ':u' => in_array('update', $actions, true) ? 1 : 0,
                ':d' => in_array('delete', $actions, true) ? 1 : 0,
                ':max' => $maxRows,
            ];
            $find->execute([$dbId, $entity]);
            ($find->fetchColumn() === false ? $insertPerm : $updatePerm)->execute($params);
        }

        return ['client_id' => $clientId, 'secret' => $secret, 'db_id' => $dbId];
    }

    /**
     * Write config/database.php as a self-contained array (no .env dependency),
     * so secrets never live in a plaintext file the web server might serve.
     *
     * @param array<string, mixed> $db raw driver config (mysql flat/nested or sqlite)
     */
    public function writeDatabaseConfig(array $db): void
    {
        require_once __DIR__ . '/../Database/Dsn.php';
        $c = \App\Database\Dsn::normalize($db);
        if ($c['driver'] === 'sqlite') {
            $config = ['driver' => 'sqlite', 'sqlite' => ['path' => $c['path']]];
        } else {
            $config = ['driver' => 'mysql', 'mysql' => [
                'host' => $c['host'],
                'port' => (string) $c['port'],
                'database' => $c['database'],
                'username' => $c['username'],
                'password' => $c['password'],
                'charset' => $c['charset'],
            ]];
        }
        $header = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Generated by the installer. Keep this file outside the web root and 0600.\n"
            . "// To source from the environment instead, replace a value with getenv('DB_...').\n\n"
            . 'return ';
        $this->atomicWrite($this->databaseConfigPath(), $header . $this->exportArray($config) . ";\n", 0o600);
    }

    /**
     * Write config/security.php with the operator's options and the generated
     * client secret inlined.
     *
     * @param array<string,mixed> $options
     * @param array<string,string> $clientSecrets client_id => secret
     * @param array<string,mixed> $clientsConfig  client_id => per-client config
     */
    public function writeSecurityConfig(array $options, array $clientSecrets, array $clientsConfig): void
    {
        $config = [
            'timestamp_window_seconds' => (int) ($options['timestamp_window_seconds'] ?? 300),
            'max_body_bytes' => (int) ($options['max_body_bytes'] ?? 65536),
            'max_requests_per_minute' => (int) ($options['max_requests_per_minute'] ?? 60),
            'client_secrets' => $clientSecrets,
            'allow_database_secrets' => false,
            'require_https' => (bool) ($options['require_https'] ?? true),
            'dev_mode' => (bool) ($options['dev_mode'] ?? false),
            'trusted_proxies' => array_values($options['trusted_proxies'] ?? []),
            'pre_auth_rate_limit' => [
                'enabled' => (bool) ($options['pre_auth_enabled'] ?? true),
                'minute_limit' => (int) ($options['pre_auth_minute'] ?? 30),
                'hour_limit' => (int) ($options['pre_auth_hour'] ?? 500),
                'storage_dir' => $this->rateLimitDir(),
            ],
            'audit' => [
                'mode' => (string) ($options['audit_mode'] ?? 'authenticated_only'),
                'sample_rate' => 10,
                'retention_days' => 90,
            ],
            'mutation_guard' => [
                'enabled' => (bool) ($options['mutation_guard'] ?? true),
            ],
            'tenant_scope' => [
                'on_violation' => (string) ($options['tenant_on_violation'] ?? 'reject'),
            ],
            'clients' => $clientsConfig,
            'public_demo' => [
                'enabled' => false,
                'rate_limit' => ['per_minute' => 1, 'per_hour' => 10, 'per_day' => 30],
                'permissions' => [],
            ],
        ];
        $header = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Generated by the installer. Keep this file outside the web root and 0600.\n"
            . "// client_secrets hold the HMAC keys; treat this file as a credential.\n\n"
            . 'return ';
        $this->atomicWrite($this->securityConfigPath(), $header . $this->exportArray($config) . ";\n", 0o600);
    }

    /** Render a value as PHP source using short-array syntax. */
    private function exportArray(mixed $value, int $depth = 0): string
    {
        $pad = str_repeat('    ', $depth);
        $padInner = str_repeat('    ', $depth + 1);
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $isList = array_is_list($value);
            $parts = [];
            foreach ($value as $k => $v) {
                $key = $isList ? '' : var_export((string) $k, true) . ' => ';
                $parts[] = $padInner . $key . $this->exportArray($v, $depth + 1);
            }
            return "[\n" . implode(",\n", $parts) . ",\n" . $pad . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        return var_export((string) $value, true);
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory: $dir");
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Unable to write: $path");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to finalize: $path");
        }
        @chmod($path, $mode);
    }

    public function ensureStorage(): void
    {
        foreach ([$this->storageDir(), $this->rateLimitDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create storage directory: $dir");
            }
            @chmod($dir, 0o700);
        }
    }

    /**
     * Apply secure file/directory permissions. Defaults (owner-only on secrets)
     * suit the common shared-hosting model where PHP runs as the file owner.
     * Pass wider modes when the web server runs as a separate user.
     *
     * @return list<array{path:string,mode:string,ok:bool}>
     */
    public function hardenPermissions(int $configFileMode = 0o600, int $configDirMode = 0o700): array
    {
        $results = [];
        $apply = function (string $path, int $mode) use (&$results): void {
            if (!file_exists($path)) {
                return;
            }
            $ok = @chmod($path, $mode);
            $results[] = ['path' => $path, 'mode' => sprintf('%04o', $mode), 'ok' => $ok];
        };

        $apply($this->configDir(), $configDirMode);
        $apply($this->databaseConfigPath(), $configFileMode);
        $apply($this->securityConfigPath(), $configFileMode);
        $apply($this->storageDir(), 0o700);
        $apply($this->rateLimitDir(), 0o700);
        $apply($this->lockPath(), 0o600);

        // Secure the SQLite database file (and its WAL/SHM sidecars) if this is
        // a sqlite deployment.
        if (is_readable($this->databaseConfigPath())) {
            require_once __DIR__ . '/../Database/Dsn.php';
            try {
                $c = \App\Database\Dsn::normalize(require $this->databaseConfigPath());
                if (($c['driver'] ?? '') === 'sqlite') {
                    foreach (['', '-wal', '-shm'] as $suffix) {
                        $apply($c['path'] . $suffix, 0o600);
                    }
                }
            } catch (\Throwable) {
                // Non-fatal: permission hardening is best-effort.
            }
        }

        foreach (glob($this->root . '/bin/*') ?: [] as $script) {
            $apply($script, 0o750);
        }
        // Web root: readable/traversable but never writable by group/other.
        $apply($this->root . '/public', 0o755);
        $apply($this->root . '/public/index.php', 0o644);
        $apply($this->root . '/public/.htaccess', 0o644);

        return $results;
    }

    public function lock(string $note = ''): void
    {
        $this->ensureStorage();
        $stamp = gmdate('c');
        $this->atomicWrite(
            $this->lockPath(),
            "Installed at {$stamp} UTC\n{$note}\nDelete public/install.php now.\n",
            0o600
        );
    }
}
