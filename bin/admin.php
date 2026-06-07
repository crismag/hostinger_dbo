<?php

/**
 * CLI administration for a running gateway. Manages entities, clients, and
 * permissions in the database, and client secrets in config/security.php.
 *
 *   php bin/admin.php help
 *
 * This is the safe, shell-only admin surface. A privileged web admin page is a
 * separate, opt-in component (its authentication model must be ratified first).
 */

declare(strict_types=1);

use App\Admin\AdminService;
use App\Database\Dsn;
use App\Install\Installer;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
});

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}
function fail(string $s): never
{
    fwrite(STDERR, "[error] $s\n");
    exit(1);
}
/** @param list<string> $argv */
function opt(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $i => $arg) {
        if ($arg === "--$name" && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($arg, "--$name=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}
/** @param list<string> $csv */
function csv(?string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== ''));
}
/** @param list<array<string,mixed>> $rows */
function table(array $rows): void
{
    if ($rows === []) {
        out('  (none)');
        return;
    }
    $cols = array_keys($rows[0]);
    out('  ' . implode("\t", $cols));
    foreach ($rows as $row) {
        out('  ' . implode("\t", array_map(static fn ($c): string => (string) $row[$c], $cols)));
    }
}

$installer = new Installer($root);
$cmd = $argv[1] ?? 'help';

if ($cmd === 'help' || $cmd === '--help') {
    out('php bin/admin.php <command>');
    out('  status                            backend driver + row counts');
    out('  health                            environment + config checks');
    out('  entity:list                       list registered entities');
    out('  entity:enable <name>              enable an entity');
    out('  entity:disable <name>             disable an entity');
    out('  client:list                       list clients + status');
    out('  client:create --id X [--name N] --entities a,b --actions select,insert');
    out('  client:enable|disable|revoke <id> change client status');
    out('  client:rotate <id>                issue a new HMAC secret (shown once)');
    out('  perms:list <client>               show a client\'s permissions');
    out('  perms:set --client X --entity Y --actions select,insert [--max 100]');
    exit(0);
}

if ($cmd === 'health') {
    $ok = true;
    foreach ($installer->preflight() as $c) {
        out(sprintf('  [%s] %-22s %s', $c['ok'] ? 'ok' : ($c['fatal'] ? 'XX' : '!!'), $c['name'], $c['detail']));
        if ($c['fatal'] && !$c['ok']) {
            $ok = false;
        }
    }
    $hasDb = is_readable($root . '/config/database.php');
    $hasSec = is_readable($root . '/config/security.php');
    out(sprintf('  [%s] %-22s %s', $hasDb ? 'ok' : 'XX', 'database config', $hasDb ? 'present' : 'missing — run installer'));
    out(sprintf('  [%s] %-22s %s', $hasSec ? 'ok' : 'XX', 'security config', $hasSec ? 'present' : 'missing — run installer'));
    exit($ok && $hasDb && $hasSec ? 0 : 1);
}

// Remaining commands need the database.
$dbConfig = $root . '/config/database.php';
if (!is_readable($dbConfig)) {
    fail('config/database.php not found — run the installer first.');
}
try {
    $pdo = Dsn::connect(require $dbConfig);
} catch (Throwable $e) {
    fail('Database connection failed: ' . $e->getMessage());
}
$admin = new AdminService($pdo);

try {
    switch ($cmd) {
        case 'status':
            $s = $admin->status();
            out("  driver:      {$s['driver']}");
            out("  clients:     {$s['clients']}");
            out("  entities:    {$s['entities']}");
            out("  audit_logs:  {$s['audit_logs']}");
            break;

        case 'entity:list':
            table($admin->entities());
            break;

        case 'entity:enable':
        case 'entity:disable':
            $name = $argv[2] ?? fail("usage: $cmd <entity>");
            $enable = $cmd === 'entity:enable';
            out($admin->setEntityEnabled($name, $enable) ? "  $name " . ($enable ? 'enabled' : 'disabled') : "  no such entity: $name");
            break;

        case 'client:list':
            table($admin->clients());
            break;

        case 'client:create':
            $id = opt($argv, 'id') ?? fail('--id is required');
            $entities = csv(opt($argv, 'entities'));
            $actions = csv(opt($argv, 'actions', 'select'));
            if ($entities === []) {
                fail('--entities is required (comma list)');
            }
            $client = $installer->createClient($pdo, $id, opt($argv, 'name', $id) ?? $id, $entities, $actions);
            $sec = $installer->loadSecurity();
            $sec['client_secrets'][$client['client_id']] = $client['secret'];
            $sec['clients'][$client['client_id']] ??= ['enforced_filters' => [], 'allow_bulk_updates' => false];
            $installer->saveSecurity($sec);
            $installer->hardenPermissions();
            out("  created client '{$client['client_id']}' (db id {$client['db_id']})");
            out('  entities: ' . implode(',', $entities) . ' | actions: ' . implode(',', $actions));
            out("  HMAC secret (shown once): {$client['secret']}");
            break;

        case 'client:enable':
        case 'client:disable':
        case 'client:revoke':
            $id = $argv[2] ?? fail("usage: $cmd <client_id>");
            $status = ['client:enable' => 'active', 'client:disable' => 'disabled', 'client:revoke' => 'revoked'][$cmd];
            out($admin->setClientStatus($id, $status) ? "  $id → $status" : "  no such client: $id");
            break;

        case 'client:rotate':
            $id = $argv[2] ?? fail('usage: client:rotate <client_id>');
            if ($admin->clientDbId($id) === null) {
                fail("no such client: $id");
            }
            $secret = $installer->generateSecret();
            $sec = $installer->loadSecurity();
            $sec['client_secrets'][$id] = $secret;
            $installer->saveSecurity($sec);
            $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
            $pdo->prepare("UPDATE api_clients SET rotated_at = $now WHERE client_id = ?")->execute([$id]);
            out("  rotated '$id'. New HMAC secret (shown once): $secret");
            break;

        case 'perms:list':
            $id = $argv[2] ?? fail('usage: perms:list <client_id>');
            table($admin->permissions($id));
            break;

        case 'perms:set':
            $id = opt($argv, 'client') ?? fail('--client is required');
            $entity = opt($argv, 'entity') ?? fail('--entity is required');
            $admin->setPermissions($id, $entity, csv(opt($argv, 'actions')), (int) (opt($argv, 'max', '100') ?? 100));
            out("  permissions set for '$id' on '$entity'");
            break;

        default:
            fail("unknown command: $cmd (try: php bin/admin.php help)");
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
