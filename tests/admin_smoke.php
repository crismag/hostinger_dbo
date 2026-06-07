<?php

/**
 * AdminService smoke test (SQLite-backed). Covers the DB-side admin operations:
 * status, entity enable/disable, client status, and permission upserts.
 *
 *     php tests/admin_smoke.php
 */

declare(strict_types=1);

use App\Admin\AdminService;
use App\Database\Dsn;

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
function throws(callable $fn): bool {
    try { $fn(); return false; } catch (Throwable) { return true; }
}

$pdo = new PDO('sqlite::memory:', options: Dsn::OPTIONS);
$pdo->exec("CREATE TABLE api_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT UNIQUE, client_name TEXT, status TEXT DEFAULT 'active', secret_hash TEXT, rotated_at TEXT)");
$pdo->exec('CREATE TABLE api_client_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, entity_name TEXT, can_select INTEGER DEFAULT 0, can_insert INTEGER DEFAULT 0, can_update INTEGER DEFAULT 0, can_delete INTEGER DEFAULT 0, max_rows_per_select INTEGER DEFAULT 100, UNIQUE(client_id, entity_name))');
$pdo->exec('CREATE TABLE api_entities (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_name TEXT UNIQUE, table_name TEXT, enabled INTEGER DEFAULT 1, schema_json TEXT)');
$pdo->exec('CREATE TABLE api_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT)');
$pdo->exec("INSERT INTO api_clients (client_id, client_name, status, secret_hash) VALUES ('c1', 'C1', 'active', 'x')");
$pdo->exec("INSERT INTO api_entities (entity_name, table_name, enabled, schema_json) VALUES ('tickets', 'tickets', 1, '{}')");

$admin = new AdminService($pdo);

$s = $admin->status();
check('status: driver + counts', $s['driver'] === 'sqlite' && $s['clients'] === 1 && $s['entities'] === 1, json_encode($s));

check('entities: list', count($admin->entities()) === 1 && $admin->entities()[0]['entity_name'] === 'tickets');
check('entity: disable', $admin->setEntityEnabled('tickets', false) && (int) $admin->entities()[0]['enabled'] === 0);
check('entity: enable', $admin->setEntityEnabled('tickets', true) && (int) $admin->entities()[0]['enabled'] === 1);
check('entity: unknown returns false', !$admin->setEntityEnabled('nope', false));

check('clients: list', $admin->clients()[0]['status'] === 'active');
check('client: set status', $admin->setClientStatus('c1', 'disabled') && $admin->clients()[0]['status'] === 'disabled');
check('client: invalid status throws', throws(fn () => $admin->setClientStatus('c1', 'bogus')));
check('client: unknown returns false', !$admin->setClientStatus('nope', 'active'));
check('clientDbId', $admin->clientDbId('c1') === 1 && $admin->clientDbId('nope') === null);

$admin->setPermissions('c1', 'tickets', ['select', 'insert'], 50);
$p = $admin->permissions('c1');
check('perms: insert grant', count($p) === 1 && (int) $p[0]['can_select'] === 1 && (int) $p[0]['can_insert'] === 1 && (int) $p[0]['can_update'] === 0 && (int) $p[0]['max_rows_per_select'] === 50);
$admin->setPermissions('c1', 'tickets', ['select'], 100);
$p = $admin->permissions('c1');
check('perms: update grant (upsert)', (int) $p[0]['can_insert'] === 0 && (int) $p[0]['max_rows_per_select'] === 100);
check('perms: unknown client throws', throws(fn () => $admin->setPermissions('nope', 'tickets', ['select'])));

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
