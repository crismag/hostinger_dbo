<?php

/**
 * @file install.php
 *
 * Runs the command-line installer for database setup, client creation, configuration generation, and filesystem hardening.
 *
 * Creation Date: 2026-06-02
 * Inputs: Command-line options, environment variables, and runtime configuration files.
 * Outputs: Writes operational status to the console and updates gateway state as described below.
 * Usage: php bin/install.php [--non-interactive]
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

/**
 * CLI installer wizard for the DBO REST gateway.
 *
 * Interactive:      php bin/install.php
 * Non-interactive:  INSTALL_NONINTERACTIVE=1 DB_HOST=... DB_PASSWORD=... php bin/install.php
 *                   (or pass --non-interactive)
 *
 * Recognised environment variables (all optional in interactive mode):
 *   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_CHARSET
 *   INSTALL_CREATE_DATABASE=1        create the database if missing
 *   INSTALL_WITH_EXAMPLES=1          load the example object tables
 *   INSTALL_CLIENT_ID=...            first API client id
 *   INSTALL_CLIENT_NAME=...          human label
 *   INSTALL_CLIENT_ACTIONS=select,insert,update,delete
 *   INSTALL_CLIENT_ENTITIES=projects,users   (default: all registered)
 *   INSTALL_REQUIRE_HTTPS=1          enforce HTTPS (default 1)
 *   INSTALL_TRUSTED_PROXIES=10.0.0.1,2001:db8::/32
 *   INSTALL_FORCE=1                  re-run even if already installed
 */

use App\Install\Installer;

$root = dirname(__DIR__);
require $root . '/src/Install/Installer.php';

$installer = new Installer($root);
$interactive = getenv('INSTALL_NONINTERACTIVE') === false
    && !in_array('--non-interactive', $argv, true)
    && stream_isatty(STDIN);

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}
function err(string $s): void
{
    fwrite(STDERR, $s . "\n");
}
function fail(string $s): never
{
    err("\n[FAIL] $s");
    exit(1);
}
/** Prompt with a default; in non-interactive mode return the default immediately. */
function ask(bool $interactive, string $label, string $default = '', bool $secret = false): string
{
    if (!$interactive) {
        return $default;
    }
    $suffix = $default !== '' && !$secret ? " [$default]" : '';
    fwrite(STDOUT, "$label$suffix: ");
    if ($secret && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($secret && function_exists('shell_exec')) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    $line = $line === false ? '' : trim($line);
    return $line !== '' ? $line : $default;
}
function askYesNo(bool $interactive, string $label, bool $default): bool
{
    if (!$interactive) {
        return $default;
    }
    $answer = strtolower(ask($interactive, $label . ($default ? ' (Y/n)' : ' (y/N)')));
    if ($answer === '') {
        return $default;
    }
    return in_array($answer, ['y', 'yes', '1', 'true'], true);
}
function envOr(string $name, string $default): string
{
    $v = getenv($name);
    return $v === false ? $default : $v;
}
function envBool(string $name, bool $default): bool
{
    $v = getenv($name);
    if ($v === false) {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'yes', 'true', 'on'], true);
}

out('==> DBO REST Gateway installer');
out($interactive ? '    mode: interactive' : '    mode: non-interactive');

if ($installer->isInstalled() && !envBool('INSTALL_FORCE', false)) {
    out("\nAlready installed (config/security.php or storage/.install-lock present).");
    out('Set INSTALL_FORCE=1 to re-run. Aborting without changes.');
    exit(0);
}

// --- Step 1: preflight ---------------------------------------------------
out("\n[1/6] Environment checks");
$fatal = false;
foreach ($installer->preflight() as $c) {
    $mark = $c['ok'] ? 'ok ' : ($c['fatal'] ? 'XX ' : '!! ');
    out(sprintf('    [%s] %-22s %s', $mark, $c['name'], $c['detail']));
    if ($c['fatal'] && !$c['ok']) {
        $fatal = true;
    }
}
if ($fatal) {
    fail('Fatal preflight checks failed. Resolve the items marked XX and retry.');
}

// --- Step 2: database connection ----------------------------------------
out("\n[2/6] Database connection");
$db = [
    'host' => ask($interactive, '  DB host', envOr('DB_HOST', 'localhost')),
    'port' => ask($interactive, '  DB port', envOr('DB_PORT', '3306')),
    'database' => ask($interactive, '  DB name', envOr('DB_DATABASE', 'dbo_gateway')),
    'username' => ask($interactive, '  DB user', envOr('DB_USERNAME', 'root')),
    'password' => $interactive
        ? ask($interactive, '  DB password', envOr('DB_PASSWORD', ''), true)
        : envOr('DB_PASSWORD', ''),
    'charset' => envOr('DB_CHARSET', 'utf8mb4'),
];
$createDb = $interactive
    ? askYesNo($interactive, '  Create database if missing?', false)
    : envBool('INSTALL_CREATE_DATABASE', false);

$test = $installer->testDatabase($db, $createDb);
if (!$test['ok']) {
    fail('Database connection failed: ' . $test['error']);
}
out('    connected. existing tables: ' . (count($test['tables']) ?: 'none'));

$pdo = $installer->connect($db, false);

// --- Step 3: schema ------------------------------------------------------
out("\n[3/6] Schema");
$withExamples = $interactive
    ? askYesNo($interactive, '  Load example object tables (projects/users)?', true)
    : envBool('INSTALL_WITH_EXAMPLES', true);
foreach ($installer->loadSchema($pdo, $withExamples) as $r) {
    out(sprintf('    %-22s %s', $r['file'], $r['note']));
}

// --- Step 4: first API client -------------------------------------------
out("\n[4/6] First API client");
$entitiesAll = $installer->registeredEntities($pdo);
$clientId = ask($interactive, '  Client id', envOr('INSTALL_CLIENT_ID', 'primary-client'));
$clientName = ask($interactive, '  Client name', envOr('INSTALL_CLIENT_NAME', 'Primary service'));

$entitiesEnv = envOr('INSTALL_CLIENT_ENTITIES', '');
$entities = $entitiesEnv !== '' ? array_map('trim', explode(',', $entitiesEnv)) : $entitiesAll;
if ($interactive && $entitiesAll !== []) {
    $picked = ask($interactive, '  Entities (comma list)', implode(',', $entitiesAll));
    $entities = array_map('trim', explode(',', $picked));
}
$entities = array_values(array_filter($entities, static fn (string $e): bool => in_array($e, $entitiesAll, true)));
if ($entities === [] && $entitiesAll !== []) {
    $entities = $entitiesAll;
}

$actionsDefault = envOr('INSTALL_CLIENT_ACTIONS', 'select');
$actionsRaw = $interactive
    ? ask($interactive, '  Allowed actions (select,insert,update,delete)', $actionsDefault)
    : $actionsDefault;
$actions = array_map('trim', explode(',', $actionsRaw));

$client = $installer->createClient($pdo, $clientId, $clientName, $entities, $actions);
out("    created client '{$client['client_id']}' (db id {$client['db_id']})");
out('    entities: ' . (implode(', ', $entities) ?: 'none') . ' | actions: ' . implode(',', array_intersect(Installer::ACTIONS, $actions)));

// --- Step 5: security options + write config -----------------------------
out("\n[5/6] Security configuration");
$requireHttps = $interactive
    ? askYesNo($interactive, '  Enforce HTTPS?', true)
    : envBool('INSTALL_REQUIRE_HTTPS', true);
$proxiesRaw = $interactive
    ? ask($interactive, '  Trusted proxies (comma, blank if none)', envOr('INSTALL_TRUSTED_PROXIES', ''))
    : envOr('INSTALL_TRUSTED_PROXIES', '');
$trustedProxies = array_values(array_filter(array_map('trim', explode(',', $proxiesRaw)), static fn (string $p): bool => $p !== ''));

$installer->ensureStorage();
$installer->writeDatabaseConfig($db);
$installer->writeSecurityConfig(
    [
        'require_https' => $requireHttps,
        'trusted_proxies' => $trustedProxies,
    ],
    [$client['client_id'] => $client['secret']],
    [$client['client_id'] => ['enforced_filters' => [], 'allow_bulk_updates' => false]],
);
out('    wrote config/database.php and config/security.php (0600)');

// --- Step 6: permissions + lock ------------------------------------------
out("\n[6/6] Hardening file permissions");
foreach ($installer->hardenPermissions() as $p) {
    out(sprintf('    [%s] %s %s', $p['ok'] ? 'ok' : '!!', $p['mode'], $p['path']));
}
$installer->lock("client={$client['client_id']}");

out("\n========================================================================");
out('  Installation complete.');
out('========================================================================');
out("  Client id : {$client['client_id']}");
out("  HMAC secret (shown once — store it now):");
out("      {$client['secret']}");
out('');
out('  Next steps:');
out('   - Point the web server document root at the public/ directory only.');
out('   - Serve over HTTPS (require_https is ' . ($requireHttps ? 'ON' : 'OFF') . ').');
out('   - If a web installer was uploaded, delete public/install.php now.');
out('   - Smoke test:  php tests/hardening_smoke.php');
out('========================================================================');
