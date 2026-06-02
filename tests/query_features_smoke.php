<?php

/**
 * Integration smoke test for the LIKE / GROUP BY / aggregate query controls.
 *
 * Requires a configured database (config/database.php) loaded with the example
 * schema (schema/example_objects.sql). Run after installation:
 *
 *     php tests/query_features_smoke.php
 */

declare(strict_types=1);

use App\Core\ApiException;
use App\Database\QueryBuilder;
use App\Repositories\ObjectRepository;
use App\Services\ObjectService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
});

$configFile = $root . '/config/database.php';
if (!is_readable($configFile)) {
    fwrite(STDERR, "SKIP: config/database.php not found. Run the installer first.\n");
    exit(0);
}
$c = require $configFile;
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'SKIP: cannot connect to database: ' . $e->getMessage() . "\n");
    exit(0);
}

$registry = new SchemaRegistry($pdo);
$validator = new RequestValidator();
$repo = new ObjectRepository($pdo, new QueryBuilder(), $registry);
$svc = new ObjectService($repo);
$schema = $registry->get('projects');

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    printf("  [%s] %-46s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}
/** Assert that validating $body throws an ApiException with $code. */
function rejects(RequestValidator $v, $schema, array $body, string $code): void {
    try {
        $v->validate($schema, 'select', $body);
        check("reject: expected $code", false, 'no exception thrown');
    } catch (ApiException $e) {
        check("reject with $code", $e->errorCode === $code, "got {$e->errorCode}");
    }
}

// Fresh data.
$pdo->exec('DELETE FROM projects');
$seed = [
    ['tenant_id' => 'T1', 'name' => 'Alpha One',   'status' => 'active',   'description' => 'first'],
    ['tenant_id' => 'T1', 'name' => 'Alpha Two',   'status' => 'archived', 'description' => 'second'],
    ['tenant_id' => 'T2', 'name' => 'Beta One',    'status' => 'active',   'description' => 'third'],
    ['tenant_id' => 'T2', 'name' => 'Gamma',       'status' => 'active',   'description' => 'fourth'],
    ['tenant_id' => 'T2', 'name' => 'Delta',       'status' => 'archived', 'description' => 'fifth'],
];
foreach ($seed as $row) {
    $repo->insert('projects', ['data' => $row]);
}

echo "================ LIKE / search ================\n";
$v = $validator->validate($schema, 'select', [
    'fields' => ['id', 'name'],
    'filters' => [['field' => 'name', 'op' => 'like', 'value' => 'Alpha%']],
]);
$rows = $svc->execute($schema, 'select', $v);
check('LIKE Alpha% returns 2 rows', count($rows) === 2, 'rows=' . count($rows));

$v = $validator->validate($schema, 'select', [
    'fields' => ['id', 'name', 'status'],
    'where' => ['status' => 'active'],
    'filters' => [['field' => 'name', 'op' => 'like', 'value' => '%One%']],
]);
$rows = $svc->execute($schema, 'select', $v);
check('equality + LIKE combined returns 2', count($rows) === 2, 'rows=' . count($rows));

echo "\n================ GROUP BY + aggregates ================\n";
$v = $validator->validate($schema, 'select', [
    'group_by' => ['status'],
    'aggregates' => [['fn' => 'count', 'field' => 'id', 'as' => 'cnt']],
    'order_by' => 'status', 'order_dir' => 'asc',
]);
check('aggregate mode flagged', ($v['aggregate'] ?? false) === true);
$rows = $svc->execute($schema, 'select', $v);
$byStatus = [];
foreach ($rows as $r) { $byStatus[$r['status']] = (int) $r['cnt']; }
check('GROUP BY status COUNT', ($byStatus['active'] ?? 0) === 3 && ($byStatus['archived'] ?? 0) === 2, json_encode($byStatus));

$v = $validator->validate($schema, 'select', [
    'group_by' => ['status'],
    'aggregates' => [
        ['fn' => 'sum', 'field' => 'id', 'as' => 'sum_id'],
        ['fn' => 'avg', 'field' => 'id', 'as' => 'avg_id'],
        ['fn' => 'min', 'field' => 'id', 'as' => 'min_id'],
        ['fn' => 'max', 'field' => 'id', 'as' => 'max_id'],
        ['fn' => 'count', 'as' => 'n'],
    ],
    'order_by' => 'sum_id', 'order_dir' => 'desc',
]);
$rows = $svc->execute($schema, 'select', $v);
$hasAll = $rows !== [] && isset($rows[0]['sum_id'], $rows[0]['avg_id'], $rows[0]['min_id'], $rows[0]['max_id'], $rows[0]['n']);
check('SUM/AVG/MIN/MAX/COUNT(*) all returned', $hasAll, 'cols=' . implode(',', array_keys($rows[0] ?? [])));

// Aggregate scoped by an equality where (proves tenant-style scoping still applies pre-aggregation).
$v = $validator->validate($schema, 'select', [
    'where' => ['tenant_id' => 'T2'],
    'group_by' => ['status'],
    'aggregates' => [['fn' => 'count', 'as' => 'n']],
]);
$rows = $svc->execute($schema, 'select', $v);
$total = 0; foreach ($rows as $r) { $total += (int) $r['n']; }
check('aggregate honors WHERE scope (T2 has 3)', $total === 3, 'total=' . $total);

echo "\n================ validation rejections ================\n";
rejects($validator, $schema, ['fields' => ['id'], 'filters' => [['field' => 'name', 'op' => 'gt', 'value' => 'x']]], 'REQUEST_INVALID_OPERATOR');
rejects($validator, $schema, ['fields' => ['id'], 'filters' => [['field' => 'status', 'op' => 'like', 'value' => 'a%']]], 'REQUEST_FIELD_NOT_SEARCHABLE');
rejects($validator, $schema, ['group_by' => ['name'], 'aggregates' => [['fn' => 'count', 'as' => 'n']]], 'REQUEST_FIELD_NOT_GROUPABLE');
rejects($validator, $schema, ['group_by' => ['status'], 'aggregates' => [['fn' => 'sum', 'field' => 'name', 'as' => 'x']]], 'REQUEST_FIELD_NOT_AGGREGATABLE');
rejects($validator, $schema, ['group_by' => ['status'], 'aggregates' => [['fn' => 'count', 'as' => '1bad']]], 'REQUEST_INVALID_ALIAS');
rejects($validator, $schema, ['group_by' => ['status'], 'aggregates' => [['fn' => 'median', 'field' => 'id', 'as' => 'm']]], 'REQUEST_INVALID_AGGREGATE');
rejects($validator, $schema, ['group_by' => ['status'], 'aggregates' => [['fn' => 'sum', 'as' => 'x']]], 'REQUEST_INVALID_AGGREGATE');

$pdo->exec('DELETE FROM projects');

echo "\n================ RESULT ================\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
