<?php

declare(strict_types=1);

/**
 * Security-hardening smoke test (SQLite-backed).
 *
 * Covers all seven hardening findings: pre-auth rate limiting, public demo,
 * tenant scope enforcement, mutation guard, audit modes, unified auth errors,
 * and HTTPS enforcement.
 */

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_EXCEPTION, 1);
ini_set('assert.exception', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use App\Core\ApiException;
use App\Core\MiddlewarePipeline;
use App\Core\Request;
use App\Core\Router;
use App\Controllers\ObjectController;
use App\Database\QueryBuilder;
use App\Middleware\AuditMiddleware;
use App\Middleware\HmacAuthMiddleware;
use App\Middleware\HttpsMiddleware;
use App\Middleware\JsonBodyLimitMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\PreAuthRateLimitMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ReplayProtectionMiddleware;
use App\Middleware\RoutingMiddleware;
use App\Repositories\ObjectRepository;
use App\Security\ApiClientResolver;
use App\Security\FilesystemRateLimiter;
use App\Security\HmacAuth;
use App\Security\NonceStore;
use App\Security\SignatureVerifier;
use App\Services\AuditLogService;
use App\Services\MutationGuardService;
use App\Services\ObjectService;
use App\Services\PermissionService;
use App\Services\PublicDemoService;
use App\Services\RateLimitService;
use App\Services\ScopeEnforcementService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;

/** Asserts the callback throws an ApiException with the expected error code. */
function expectError(string $expectedCode, callable $callback): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        assert($exception->errorCode === $expectedCode, "expected {$expectedCode}, got {$exception->errorCode}");
        return;
    }
    assert(false, "expected {$expectedCode} but no exception was thrown");
}

$tmpRoot = sys_get_temp_dir() . '/dbo_hardening_' . getmypid();
$limiterDir = static fn (string $suffix): string => $tmpRoot . '_' . $suffix;

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE api_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT UNIQUE, status TEXT, secret_hash TEXT, allowed_ips TEXT)');
$db->exec('CREATE TABLE api_client_permissions (client_id INTEGER, entity_name TEXT, can_select INTEGER, can_insert INTEGER, can_update INTEGER, can_delete INTEGER, max_rows_per_select INTEGER, allowed_fields_json TEXT, allowed_filter_fields_json TEXT)');
$db->exec('CREATE TABLE api_nonces (client_id INTEGER, nonce TEXT, expires_at TEXT, UNIQUE(client_id, nonce))');
$db->exec('CREATE TABLE api_rate_limits (client_id INTEGER, bucket_key TEXT, request_count INTEGER, window_start TEXT, window_end TEXT, UNIQUE(client_id, bucket_key))');
$db->exec('CREATE TABLE api_entities (entity_name TEXT UNIQUE, table_name TEXT, primary_key_name TEXT, enabled INTEGER, schema_json TEXT)');
$db->exec('CREATE TABLE api_audit_logs (request_id TEXT, client_id INTEGER, entity_name TEXT, action_name TEXT, request_method TEXT, request_path TEXT, request_hash TEXT, ip_address TEXT, status_code INTEGER, success INTEGER, error_code TEXT, duration_ms INTEGER)');
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT, name TEXT, status TEXT, is_demo INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

$schemaJson = json_encode([
    'fields' => ['id', 'tenant_id', 'name', 'status', 'is_demo', 'created_at'],
    'insertable' => ['tenant_id', 'name', 'status', 'is_demo'],
    'updatable' => ['name', 'status'],
    'filterable' => ['id', 'tenant_id', 'status', 'is_demo'],
    'orderable' => ['id', 'created_at'],
], JSON_THROW_ON_ERROR);
$db->prepare('INSERT INTO api_entities VALUES (:e, :t, :pk, 1, :s)')
    ->execute(['e' => 'projects', 't' => 'projects', 'pk' => 'id', 's' => $schemaJson]);
$db->exec("INSERT INTO api_clients VALUES (1, 'client-a', 'active', 'unused', NULL)");
$db->exec("INSERT INTO api_client_permissions VALUES (1, 'projects', 1, 1, 1, 1, 100, '[\"id\",\"tenant_id\",\"name\",\"status\",\"is_demo\",\"created_at\"]', '[\"id\",\"tenant_id\",\"status\",\"is_demo\"]')");
// Seed rows: two demo, two non-demo, across tenants.
$db->exec("INSERT INTO projects (tenant_id, name, status, is_demo) VALUES ('1001','Alpha','active',1),('1001','Beta','active',1),('1001','Gamma','active',0),('2002','Delta','active',0)");

$registry = new SchemaRegistry($db);
$validator = new RequestValidator();
$schema = $registry->get('projects');

// ---------------------------------------------------------------------------
echo "Finding #1 — pre-auth rate limiting (filesystem, no DB)\n";
$preLimiter = new FilesystemRateLimiter($limiterDir('preauth'));
$preAuth = new PreAuthRateLimitMiddleware($preLimiter, ['enabled' => true, 'minute_limit' => 2, 'hour_limit' => 100]);
$pass = static fn (Request $r): \App\Core\Response => new \App\Core\Response(['ok' => true]);
$ipReq = static fn (): Request => new Request('POST', '/api/v1/projects/select', '{}', [], '203.0.113.9');
assert($preAuth->handle($ipReq(), $pass)->payload['ok'] === true); // 1
assert($preAuth->handle($ipReq(), $pass)->payload['ok'] === true); // 2
expectError('RATE_LIMITED', static fn () => $preAuth->handle($ipReq(), $pass)); // 3 trips
// Disabled limiter is a no-op.
$disabled = new PreAuthRateLimitMiddleware($preLimiter, ['enabled' => false]);
assert($disabled->handle($ipReq(), $pass)->payload['ok'] === true);

// ---------------------------------------------------------------------------
echo "Finding #7 — HTTPS enforcement\n";
$https = new HttpsMiddleware(true, false);
$plain = static fn (?string $ip, bool $secure): Request => new Request('POST', '/x', '{}', [], $ip, $secure);
expectError('HTTPS_REQUIRED', static fn () => $https->handle($plain('203.0.113.1', false), $pass));
assert($https->handle($plain('203.0.113.1', true), $pass)->payload['ok'] === true);     // secure passes
assert($https->handle($plain('127.0.0.1', false), $pass)->payload['ok'] === true);       // localhost exempt
assert((new HttpsMiddleware(true, true))->handle($plain('203.0.113.1', false), $pass)->payload['ok'] === true); // dev_mode exempt
assert((new HttpsMiddleware(false, false))->handle($plain('203.0.113.1', false), $pass)->payload['ok'] === true); // disabled
$server = $_SERVER;
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/api/v1/projects/select',
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 203.0.113.10',
];
$proxied = Request::fromGlobals(1024, ['203.0.113.10']);
assert($proxied->secure === true);
assert($proxied->ipAddress === '198.51.100.20');
$untrusted = Request::fromGlobals(1024, []);
assert($untrusted->secure === false);
assert($untrusted->ipAddress === '203.0.113.10');
$_SERVER = $server;

// ---------------------------------------------------------------------------
echo "Finding #6 — unified authentication errors\n";
$verifier = new SignatureVerifier();
$auth = new HmacAuth(new ApiClientResolver($db, ['client-a' => 'secret-a']), $verifier);
$hmacMw = new HmacAuthMiddleware($auth); // no demo
$ts = gmdate('Y-m-d\TH:i:s\Z');
$signedHeaders = static function (string $clientId, string $secret, string $body, string $nonce) use ($verifier, $ts): array {
    $sig = hash_hmac('sha256', $verifier->canonical('POST', '/api/v1/projects/select', $ts, $nonce, $body), $secret);
    return ['x-client-id' => $clientId, 'x-timestamp' => $ts, 'x-nonce' => $nonce, 'x-signature' => $sig, 'content-type' => 'application/json'];
};
// Wrong secret, unknown client, and missing headers all yield the same code.
$badSig = new Request('POST', '/api/v1/projects/select', '{}', $signedHeaders('client-a', 'WRONG', '{}', 'n1'), '10.0.0.1');
expectError('AUTHENTICATION_FAILED', static fn () => $hmacMw->handle($badSig, $pass));
$unknown = new Request('POST', '/api/v1/projects/select', '{}', $signedHeaders('ghost', 'secret-a', '{}', 'n2'), '10.0.0.1');
expectError('AUTHENTICATION_FAILED', static fn () => $hmacMw->handle($unknown, $pass));
$unsignedNoDemo = new Request('POST', '/api/v1/projects/select', '{}', ['content-type' => 'application/json'], '10.0.0.1');
expectError('AUTHENTICATION_FAILED', static fn () => $hmacMw->handle($unsignedNoDemo, $pass));

// ---------------------------------------------------------------------------
echo "Finding #3 — tenant scope enforcement\n";
$scopeReject = new ScopeEnforcementService(['client-a' => ['enforced_filters' => ['tenant_id' => '1001']]], 'reject');
// Injected into an empty where.
$scoped = $scopeReject->apply('client-a', 'select', ['where' => [], 'fields' => ['id']]);
assert($scoped['where']['tenant_id'] === '1001');
// Conflicting client-supplied value is rejected.
expectError('TENANT_SCOPE_VIOLATION', static fn () => $scopeReject->apply('client-a', 'update', ['where' => ['tenant_id' => '9999', 'id' => 1]]));
// Matching value is accepted.
$ok = $scopeReject->apply('client-a', 'select', ['where' => ['tenant_id' => '1001']]);
assert($ok['where']['tenant_id'] === '1001');
// Insert applies scope to data.
$ins = $scopeReject->apply('client-a', 'insert', ['data' => ['name' => 'X']]);
assert($ins['data']['tenant_id'] === '1001');
// Override mode rewrites instead of rejecting.
$scopeOverride = new ScopeEnforcementService(['client-a' => ['enforced_filters' => ['tenant_id' => '1001']]], 'override');
$over = $scopeOverride->apply('client-a', 'update', ['where' => ['tenant_id' => '9999', 'id' => 1]]);
assert($over['where']['tenant_id'] === '1001');
// Client without enforced filters is untouched.
$none = (new ScopeEnforcementService([], 'reject'))->apply('client-a', 'select', ['where' => []]);
assert($none['where'] === []);

// ---------------------------------------------------------------------------
echo "Finding #4 — mutation guard (primary key required)\n";
$guard = new MutationGuardService(true);
$guard->assert('client-a', 'update', $schema, ['id' => 5, 'tenant_id' => '1001']); // ok
$guard->assert('client-a', 'select', $schema, []); // select unaffected
expectError('RESTRICTIVE_WHERE_REQUIRED', static fn () => $guard->assert('client-a', 'update', $schema, ['status' => 'active']));
expectError('RESTRICTIVE_WHERE_REQUIRED', static fn () => $guard->assert('client-a', 'delete', $schema, ['tenant_id' => '1001']));
// Bulk-allowed client bypasses the guard.
$bulkGuard = new MutationGuardService(true, ['client-a' => ['allow_bulk_updates' => true]]);
$bulkGuard->assert('client-a', 'update', $schema, ['status' => 'active']);
// Disabled guard is a no-op.
(new MutationGuardService(false))->assert('client-a', 'delete', $schema, ['status' => 'active']);

// ---------------------------------------------------------------------------
echo "Finding #5 — audit modes\n";
$auditReq = static function (bool $withClient) {
    $r = new Request('POST', '/api/v1/projects/select', '{}', [], '10.0.0.1');
    $r->setAttribute('request_id', 'r');
    if ($withClient) {
        $r->setAttribute('client', ['id' => 1, 'client_id' => 'client-a']);
    }
    return $r;
};
$countAudit = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM api_audit_logs')->fetchColumn();
$db->exec('DELETE FROM api_audit_logs');
$authOnly = new AuditLogService($db, ['mode' => 'authenticated_only']);
$authOnly->write($auditReq(false), 401, false, 'AUTHENTICATION_FAILED', 1); // skipped (no client)
assert($countAudit() === 0);
$authOnly->write($auditReq(true), 200, true, null, 1); // written
assert($countAudit() === 1);
$db->exec('DELETE FROM api_audit_logs');
$critical = new AuditLogService($db, ['mode' => 'critical_only']);
$critical->write($auditReq(true), 200, true, null, 1); // success skipped
assert($countAudit() === 0);
$critical->write($auditReq(true), 500, false, 'INTERNAL_ERROR', 1); // failure written
assert($countAudit() === 1);
$db->exec('DELETE FROM api_audit_logs');
$all = new AuditLogService($db, ['mode' => 'all']);
$all->write($auditReq(false), 404, false, 'ROUTE_NOT_FOUND', 1);
assert($countAudit() === 1);

// ---------------------------------------------------------------------------
echo "Finding #2 — public demo accessor (constrained, unauthenticated)\n";
$demoConfig = [
    'enabled' => true,
    'rate_limit' => ['per_minute' => 1],
    'permissions' => [
        'projects' => [
            'select' => [
                'enabled' => true,
                'max_limit' => 5,
                'allowed_fields' => ['id', 'name', 'status'],
                'allowed_filters' => ['status'],
                'required_where' => ['is_demo' => 1],
            ],
        ],
    ],
];
$demo = new PublicDemoService($demoConfig, new FilesystemRateLimiter($limiterDir('demo')));
// Constrain caps the limit and forces the required filter.
$constrained = $demo->constrain('projects', 'select', ['fields' => ['id', 'name'], 'where' => ['status' => 'active'], 'limit' => 1000]);
assert($constrained['limit'] === 5, 'limit must be hard-capped to max_limit');
assert($constrained['where']['is_demo'] === 1, 'required_where must be injected');
assert($constrained['where']['status'] === 'active');
// Disallowed field / filter / action / entity are denied.
expectError('REQUEST_FIELD_NOT_ALLOWED', static fn () => $demo->constrain('projects', 'select', ['fields' => ['tenant_id']]));
expectError('REQUEST_FIELD_NOT_ALLOWED', static fn () => $demo->constrain('projects', 'select', ['where' => ['tenant_id' => 'x']]));
expectError('PERMISSION_DENIED', static fn () => $demo->constrain('projects', 'insert', ['data' => ['name' => 'x']]));
expectError('PERMISSION_DENIED', static fn () => $demo->constrain('users', 'select', []));
// Client cannot override the mandatory filter.
$forced = $demo->constrain('projects', 'select', ['where' => ['status' => 'active'], 'fields' => ['id']]);
assert($forced['where']['is_demo'] === 1);

// Full demo pipeline: unsigned request returns only demo rows, capped.
$repo = new ObjectRepository($db, new QueryBuilder(), $registry);
$controller = new ObjectController(new ObjectService($repo));
$permissions = new PermissionService($db);
$demoPipeline = new MiddlewarePipeline([
    new HttpsMiddleware(true, true),
    new RoutingMiddleware(new Router()),
    new JsonBodyLimitMiddleware(),
    new PreAuthRateLimitMiddleware(new FilesystemRateLimiter($limiterDir('demo_pre')), ['enabled' => true, 'minute_limit' => 100]),
    new AuditMiddleware(new AuditLogService($db, ['mode' => 'all'])),
    new HmacAuthMiddleware($auth, $demo),
    new ReplayProtectionMiddleware(new NonceStore($db)),
    new RateLimitMiddleware(new RateLimitService($db, 100), $demo),
    new PermissionMiddleware($registry, $validator, $permissions, null, null, $demo),
]);
$demoBody = '{"fields":["id","name","status"],"where":{"status":"active"},"limit":50}';
$demoRequest = new Request('POST', '/api/v1/projects/select', $demoBody, ['content-type' => 'application/json'], '198.51.100.7');
$demoRequest->setAttribute('request_id', 'demo-req');
$demoResponse = $demoPipeline->handle($demoRequest, $controller->handle(...));
assert($demoResponse->statusCode === 200);
$rows = $demoResponse->payload['data'];
assert(count($rows) === 2, 'demo must only see the two is_demo rows'); // Gamma/Delta are is_demo=0
foreach ($rows as $row) {
    assert(!array_key_exists('tenant_id', $row), 'demo fields are restricted');
}
// Second demo request from the same IP trips the per-minute demo limit.
$demoRequest2 = new Request('POST', '/api/v1/projects/select', $demoBody, ['content-type' => 'application/json'], '198.51.100.7');
$demoRequest2->setAttribute('request_id', 'demo-req-2');
expectError('RATE_LIMITED', static fn () => $demoPipeline->handle($demoRequest2, $controller->handle(...)));

// ---------------------------------------------------------------------------
echo "End-to-end — authenticated client with scope + mutation guard\n";
$scopeSvc = new ScopeEnforcementService(['client-a' => ['enforced_filters' => ['tenant_id' => '1001']]], 'reject');
$guardSvc = new MutationGuardService(true, []);
$authPipeline = new MiddlewarePipeline([
    new HttpsMiddleware(true, true),
    new RoutingMiddleware(new Router()),
    new JsonBodyLimitMiddleware(),
    new PreAuthRateLimitMiddleware(new FilesystemRateLimiter($limiterDir('auth_pre')), ['enabled' => true, 'minute_limit' => 100]),
    new AuditMiddleware(new AuditLogService($db, ['mode' => 'authenticated_only'])),
    new HmacAuthMiddleware($auth, $demo),
    new ReplayProtectionMiddleware(new NonceStore($db)),
    new RateLimitMiddleware(new RateLimitService($db, 100), $demo),
    new PermissionMiddleware($registry, $validator, $permissions, $scopeSvc, $guardSvc, $demo),
]);
// Signed select with empty where: scope confines the caller to tenant 1001 only.
$selBody = '{"fields":["id","name"],"where":{},"limit":50,"order_by":"id","order_dir":"asc"}';
$selReq = new Request('POST', '/api/v1/projects/select', $selBody, $signedHeaders('client-a', 'secret-a', $selBody, 'sn1'), '10.0.0.1');
$selReq->setAttribute('request_id', 'sel');
$selResp = $authPipeline->handle($selReq, $controller->handle(...));
assert($selResp->statusCode === 200);
foreach ($selResp->payload['data'] as $row) {
    // Only tenant 1001 rows are returned even though the caller sent an empty where.
}
assert(count($selResp->payload['data']) === 3, 'scope must restrict to tenant 1001 (3 rows)');
// Signed update without a primary key is blocked by the mutation guard.
$updBody = '{"where":{"status":"active"},"data":{"status":"archived"}}';
$updReq = new Request('POST', '/api/v1/projects/update', $updBody, signedHeaders2($verifier, 'client-a', 'secret-a', '/api/v1/projects/update', $updBody, 'sn2', $ts), '10.0.0.1');
$updReq->setAttribute('request_id', 'upd');
expectError('RESTRICTIVE_WHERE_REQUIRED', static fn () => $authPipeline->handle($updReq, $controller->handle(...)));

// Cleanup temp rate-limit directories.
foreach (['preauth', 'demo', 'demo_pre', 'auth_pre'] as $suffix) {
    foreach (glob($limiterDir($suffix) . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($limiterDir($suffix));
}

echo "\nAll hardening checks passed: pre-auth limit, HTTPS, unified auth, tenant scope, mutation guard, audit modes, public demo.\n";

/** Signs for an arbitrary path (helper for the update case above). */
function signedHeaders2(SignatureVerifier $verifier, string $clientId, string $secret, string $path, string $body, string $nonce, string $ts): array
{
    $sig = hash_hmac('sha256', $verifier->canonical('POST', $path, $ts, $nonce, $body), $secret);
    return ['x-client-id' => $clientId, 'x-timestamp' => $ts, 'x-nonce' => $nonce, 'x-signature' => $sig, 'content-type' => 'application/json'];
}
