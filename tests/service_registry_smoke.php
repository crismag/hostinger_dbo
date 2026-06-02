<?php

/**
 * Service Registry smoke test (SQLite-backed). Exercises the ServiceController:
 * allowlisted handler resolution, per-client grants, input validation, and a
 * real JOIN+aggregate reference operation that the generic gateway cannot express.
 *
 *     php tests/service_smoke.php
 */

declare(strict_types=1);

use App\Controllers\ServiceController;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Dsn;
use App\Services\Operations\OperationRegistry;

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
    printf("  [%s] %-44s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

$pdo = new PDO('sqlite::memory:', options: Dsn::OPTIONS);
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT, name TEXT, status TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT, name TEXT, email TEXT, status TEXT)');
foreach ([['acme', 'P1'], ['acme', 'P2'], ['acme', 'P3'], ['globex', 'G1']] as $r) {
    $pdo->prepare('INSERT INTO projects (tenant_id, name, status) VALUES (?,?,?)')->execute([$r[0], $r[1], 'open']);
}
foreach ([['acme', 'a@x'], ['acme', 'b@x'], ['globex', 'g@x']] as $r) {
    $pdo->prepare('INSERT INTO users (tenant_id, name, email, status) VALUES (?,?,?,?)')->execute([$r[0], 'n', $r[1], 'active']);
}

$services = ['reports' => ['tenant_summary' => ['handler' => 'reports.tenant_summary']]];
$clients = [
    'svc-client' => ['enforced_filters' => [], 'services' => ['reports.tenant_summary']],
    'no-grant' => ['enforced_filters' => []],
    'scoped-client' => ['enforced_filters' => ['tenant_id' => 'acme'], 'services' => ['reports.tenant_summary']],
];
$controller = new ServiceController(new OperationRegistry(), $services, $clients, $pdo);

/** Build a service Request and run it through the controller. */
function run(ServiceController $c, string $service, string $op, array $body, string $clientId): Response {
    $req = new Request('POST', "/api/v1/services/$service/$op", json_encode($body), ['content-type' => 'application/json'], '127.0.0.1');
    $req->setAttribute('route_kind', 'service');
    $req->setAttribute('service', $service);
    $req->setAttribute('operation', $op);
    $req->setAttribute('request_id', 'test-req');
    $req->setAttribute('client', ['id' => 1, 'client_id' => $clientId, 'secret' => 'x']);
    return $c->handle($req);
}
/** Assert that a call throws ApiException with the expected code. */
function expectError(ServiceController $c, string $service, string $op, array $body, string $clientId, string $code): void {
    try {
        run($c, $service, $op, $body, $clientId);
        check("expected $code", false, 'no exception');
    } catch (ApiException $e) {
        check("rejects with $code", $e->errorCode === $code, 'got ' . $e->errorCode);
    }
}

// Happy path: granted client, real JOIN + aggregate.
$resp = run($controller, 'reports', 'tenant_summary', ['limit' => 10], 'svc-client');
$data = $resp->payload['data'];
$byTenant = [];
foreach ($data as $row) { $byTenant[$row['tenant_id']] = [(int) $row['projects'], (int) $row['users']]; }
check('granted JOIN report → 200', $resp->statusCode === 200 && ($byTenant['acme'] ?? null) === [3, 2] && ($byTenant['globex'] ?? null) === [1, 1], json_encode($byTenant));
check('meta carries service/operation/count', ($resp->payload['meta']['operation'] ?? '') === 'reports/tenant_summary' && ($resp->payload['meta']['count'] ?? 0) === 2);

// Authorization + resolution + input validation.
expectError($controller, 'reports', 'tenant_summary', ['limit' => 10], 'no-grant', 'PERMISSION_DENIED');
expectError($controller, 'reports', 'no_such_op', ['limit' => 1], 'svc-client', 'SERVICE_OPERATION_NOT_FOUND');
expectError($controller, 'billing', 'run', ['limit' => 1], 'svc-client', 'SERVICE_NOT_FOUND');
expectError($controller, 'reports', 'tenant_summary', ['bogus' => 1], 'svc-client', 'SERVICE_INPUT_INVALID');
expectError($controller, 'reports', 'tenant_summary', ['limit' => 99999], 'svc-client', 'SERVICE_INPUT_INVALID');

// Tenant scope: a client scoped to 'acme' must NEVER see globex in service results.
$scoped = run($controller, 'reports', 'tenant_summary', ['limit' => 10], 'scoped-client');
$tenants = array_column($scoped->payload['data'], 'tenant_id');
$acme = null;
foreach ($scoped->payload['data'] as $row) { if ($row['tenant_id'] === 'acme') { $acme = [(int) $row['projects'], (int) $row['users']]; } }
check('scoped client sees only its tenant', $tenants === ['acme'] && $acme === [3, 2], 'tenants=' . json_encode($tenants));
check('foreign tenant (globex) never leaks', !in_array('globex', $tenants, true));

// scopedWhere helper: merge and conflict behaviour.
$ctxScoped = new App\Services\Operations\ServiceContext($pdo, ['id' => 1, 'client_id' => 'scoped-client', 'secret' => 'x'], ['tenant_id' => 'acme']);
check('scopedWhere merges enforced filter', $ctxScoped->scopedWhere(['status' => 'open']) === ['status' => 'open', 'tenant_id' => 'acme']);
try {
    $ctxScoped->scopedWhere(['tenant_id' => 'globex']);
    check('scopedWhere rejects conflict', false, 'no exception');
} catch (ApiException $e) {
    check('scopedWhere rejects conflict', $e->errorCode === 'TENANT_SCOPE_VIOLATION', 'got ' . $e->errorCode);
}
$ctxUnscoped = new App\Services\Operations\ServiceContext($pdo, ['id' => 1, 'client_id' => 'x', 'secret' => 'x'], []);
$p = [];
check('unscoped bindScopedWhere is empty', $ctxUnscoped->bindScopedWhere([], $p, 'p') === '' && $p === []);

// The allowlist itself rejects keys not in the compile-time map.
try {
    (new OperationRegistry())->resolve('reports.not_registered');
    check('allowlist rejects unknown handler key', false, 'no exception');
} catch (ApiException $e) {
    check('allowlist rejects unknown handler key', $e->errorCode === 'SERVICE_OPERATION_NOT_FOUND', 'got ' . $e->errorCode);
}

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
