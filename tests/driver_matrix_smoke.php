<?php

/**
 * Driver-compatibility smoke test: runs the same CRUD + query-control assertions
 * through the gateway's classes against SQLite (always) and MySQL (when the
 * configured database is reachable), proving cross-driver parity.
 *
 * Uses a uniquely-named temporary entity/table so it never touches real data.
 *
 *     php tests/driver_matrix_smoke.php
 */

declare(strict_types=1);

use App\Database\Dsn;
use App\Database\QueryBuilder;
use App\Repositories\ObjectRepository;
use App\Services\ObjectService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;

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
    printf("    [%s] %-42s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

/** Run the shared assertion suite against one connected PDO of a given driver. */
function runSuite(PDO $pdo, string $driver): void
{
    $auto = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $table = '_dm_projects';
    $entity = '_dm_projects';
    // Clean slate (covers a previous aborted run).
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
    $pdo->exec("DELETE FROM api_entities WHERE entity_name = '$entity'");

    $pdo->exec("CREATE TABLE `$table` (id $auto, tenant_id VARCHAR(64), name VARCHAR(190), status VARCHAR(64))");
    $policy = json_encode([
        'fields' => ['id', 'tenant_id', 'name', 'status'],
        'insertable' => ['tenant_id', 'name', 'status'],
        'updatable' => ['name', 'status'],
        'filterable' => ['id', 'tenant_id', 'status'],
        'orderable' => ['id'],
        'searchable' => ['name'],
        'groupable' => ['status'],
        'aggregatable' => ['id'],
    ]);
    $ins = $pdo->prepare('INSERT INTO api_entities (entity_name, table_name, primary_key_name, enabled, schema_json) VALUES (?,?,?,1,?)');
    $ins->execute([$entity, $table, 'id', $policy]);

    $registry = new SchemaRegistry($pdo);
    $validator = new RequestValidator();
    $svc = new ObjectService(new ObjectRepository($pdo, new QueryBuilder(), $registry));
    $schema = $registry->get($entity);

    // CRUD insert
    foreach ([['T1', 'Login bug', 'open'], ['T1', 'Logout bug', 'open'], ['T2', 'Payment', 'closed']] as $r) {
        $svc->execute($schema, 'insert', $validator->validate($schema, 'insert', ['data' => ['tenant_id' => $r[0], 'name' => $r[1], 'status' => $r[2]]]));
    }
    // LIKE
    $like = $svc->execute($schema, 'select', $validator->validate($schema, 'select', ['fields' => ['id', 'name'], 'filters' => [['field' => 'name', 'op' => 'like', 'value' => 'Log%']]]));
    check("$driver: LIKE Log% → 2", count($like) === 2, 'rows=' . count($like));
    // GROUP BY + aggregates
    $agg = $svc->execute($schema, 'select', $validator->validate($schema, 'select', ['group_by' => ['status'], 'aggregates' => [['fn' => 'count', 'field' => 'id', 'as' => 'n'], ['fn' => 'max', 'field' => 'id', 'as' => 'mx']], 'order_by' => 'status']));
    $byStatus = [];
    foreach ($agg as $row) { $byStatus[$row['status']] = (int) $row['n']; }
    check("$driver: GROUP BY status COUNT", ($byStatus['open'] ?? 0) === 2 && ($byStatus['closed'] ?? 0) === 1, json_encode($byStatus));
    // equality filter + sort + paginate
    $sel = $svc->execute($schema, 'select', $validator->validate($schema, 'select', ['fields' => ['id', 'name'], 'where' => ['status' => 'open'], 'order_by' => 'id', 'order_dir' => 'desc', 'limit' => 1]));
    check("$driver: filter+sort+limit", count($sel) === 1 && $sel[0]['name'] === 'Logout bug', 'name=' . ($sel[0]['name'] ?? '-'));

    // cleanup
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
    $pdo->exec("DELETE FROM api_entities WHERE entity_name = '$entity'");
}

echo "================ SQLite (in-memory) ================\n";
$sqlite = new PDO('sqlite::memory:', options: Dsn::OPTIONS);
$sqlite->exec("CREATE TABLE api_entities (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_name TEXT UNIQUE, table_name TEXT, primary_key_name TEXT, enabled INTEGER, schema_json TEXT)");
runSuite($sqlite, 'sqlite');

echo "\n================ MySQL (configured, if reachable) ================\n";
$configFile = $root . '/config/database.php';
$ran = false;
if (is_readable($configFile)) {
    $cfg = Dsn::normalize(require $configFile);
    if ($cfg['driver'] === 'mysql') {
        try {
            $mysql = Dsn::connect($cfg);
            // api_entities must already exist (installed schema).
            $mysql->query('SELECT 1 FROM api_entities LIMIT 1');
            runSuite($mysql, 'mysql');
            $ran = true;
        } catch (Throwable $e) {
            echo "    SKIP: " . $e->getMessage() . "\n";
        }
    }
}
if (!$ran) {
    echo "    SKIP: configured driver is not a reachable mysql with installed schema.\n";
}

echo "\n================ RESULT ================\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
