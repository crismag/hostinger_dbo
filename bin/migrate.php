<?php

/**
 * Apply forward schema migrations to an installed gateway.
 *
 *   php bin/migrate.php status   # show applied + pending
 *   php bin/migrate.php up       # apply pending migrations
 *
 * Fresh installs run migrations automatically; this is for upgrading an existing
 * deployment to a newer release's schema.
 */

declare(strict_types=1);

use App\Database\Dsn;
use App\Install\MigrationRunner;

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

$dbConfig = $root . '/config/database.php';
if (!is_readable($dbConfig)) {
    fail('config/database.php not found — run the installer first.');
}
try {
    $pdo = Dsn::connect(require $dbConfig);
} catch (Throwable $e) {
    fail('Database connection failed: ' . $e->getMessage());
}

$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$dir = $root . '/schema' . ($driver === 'sqlite' ? '/sqlite' : '') . '/migrations';
$runner = new MigrationRunner($pdo, $dir);

switch ($argv[1] ?? 'status') {
    case 'status':
        out('  driver:  ' . $driver);
        out('  applied: ' . (implode(', ', $runner->applied()) ?: 'none'));
        out('  pending: ' . (implode(', ', $runner->pending()) ?: 'none'));
        break;

    case 'up':
        $done = $runner->migrate();
        out($done !== [] ? '  applied: ' . implode(', ', $done) : '  nothing to apply (up to date)');
        break;

    default:
        out('usage: php bin/migrate.php [status|up]');
        exit(1);
}
