<?php

/**
 * One-command setup for the TicketDesk demo: installs the app from its manifest
 * (SQLite database, object schema, registered entities, scoped client) and loads
 * the demo seed data. Re-run with FORCE=1 to rebuild from scratch.
 *
 *     php apps/demo-ticketdesk/setup.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/src/Install/Installer.php';
require $root . '/src/Config/AppDefinition.php';

use App\Config\AppDefinition;
use App\Install\Installer;

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}
function fail(string $s): never
{
    fwrite(STDERR, "[FAIL] $s\n");
    exit(1);
}

$installer = new Installer($root);
$force = getenv('FORCE') !== false;
if ($installer->isInstalled() && !$force) {
    fail('Gateway is already installed. Re-run with FORCE=1 to reinstall (overwrites config + database).');
}
@unlink($root . '/storage/.install-lock');

$def = AppDefinition::load(__DIR__ . '/app.json');
$dbPath = $root . '/' . ltrim($def->database(), '/');
$db = ['driver' => 'sqlite', 'path' => $dbPath];

out('==> Installing TicketDesk demo  (sqlite: ' . $def->database() . ')');
if ($force) {
    foreach (['', '-wal', '-shm'] as $suffix) {
        @unlink($dbPath . $suffix);
    }
}

$installer->ensureStorage();
$test = $installer->testDatabase($db, false);
if (!$test['ok']) {
    fail('Database error: ' . $test['error']);
}
$pdo = $installer->connect($db, false);

foreach ($installer->loadSchema($pdo, false) as $r) {
    out('    ' . $r['file'] . ': ' . $r['note']);
}

$installer->importSql($pdo, __DIR__ . '/data/schema.sql');
out('    app object schema imported');

$registry = json_decode((string) file_get_contents(__DIR__ . '/data/registry.json'), true);
$defs = [];
foreach ($def->entities() as $name) {
    $defs[$name] = $registry[$name] ?? fail("registry.json missing entity: $name");
}
foreach ($installer->registerEntities($pdo, $defs) as $r) {
    out('    entity ' . $r['entity'] . ': ' . $r['action']);
}

$ticketCount = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
if ($ticketCount === 0) {
    $installer->importSql($pdo, __DIR__ . '/data/seed.sql');
    out('    seed data loaded');
} else {
    out("    tickets already present ($ticketCount) — seed skipped");
}

$client = $installer->createClient($pdo, 'ticketdesk-app', 'TicketDesk demo', $def->entities(), ['select', 'insert', 'update', 'delete']);

// Local demo: serve over HTTP on localhost (dev_mode), secret stays server-side.
$installer->writeDatabaseConfig($db);
$installer->writeSecurityConfig(
    ['require_https' => false, 'dev_mode' => true, 'trusted_proxies' => []],
    [$client['client_id'] => $client['secret']],
    [$client['client_id'] => [
        'enforced_filters' => [],
        'allow_bulk_updates' => false,
        'services' => ['tickets.agent_workload', 'tickets.create_with_comment'],
    ]],
);
$installer->hardenPermissions();
$installer->lock('app=ticketdesk demo');

out('');
out('==> Done. Demo client "' . $client['client_id'] . '" created (secret stored in config/security.php).');
out('');
out('  1) Start the gateway:   php -S 127.0.0.1:8000 -t public public/index.php');
out('  2) Start the demo:      php -S 127.0.0.1:8001 -t apps/demo-ticketdesk/public apps/demo-ticketdesk/public/index.php');
out('  3) Open:                http://127.0.0.1:8001/');
out('');
out('  The browser talks only to the demo (8001); the demo signs requests to the gateway (8000).');
