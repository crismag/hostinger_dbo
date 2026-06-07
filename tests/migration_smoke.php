<?php

/**
 * Schema migration runner smoke test (SQLite-backed).
 *
 *     php tests/migration_smoke.php
 */

declare(strict_types=1);

use App\Database\Dsn;
use App\Install\MigrationRunner;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $f = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_readable($f)) {
            require_once $f;
        }
    }
});

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    printf("  [%s] %-40s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

$pdo = new PDO('sqlite::memory:', options: Dsn::OPTIONS);
$pdo->exec('CREATE TABLE api_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, ip_address TEXT)');
$runner = new MigrationRunner($pdo, $root . '/schema/sqlite/migrations');

check('available + pending list 0001', $runner->available() === ['0001_audit_ip_index'] && $runner->pending() === ['0001_audit_ip_index']);
check('nothing applied initially', $runner->applied() === []);

$done = $runner->migrate();
check('migrate applies 0001', $done === ['0001_audit_ip_index']);
check('applied now records 0001', $runner->applied() === ['0001_audit_ip_index']);
check('pending empty after migrate', $runner->pending() === []);
check('migrate is idempotent', $runner->migrate() === []);

$idx = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_audit_ip'")->fetchColumn();
check('index idx_audit_ip created', $idx === 'idx_audit_ip');

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
