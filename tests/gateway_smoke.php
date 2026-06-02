<?php

declare(strict_types=1);

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_EXCEPTION, 1);
ini_set('assert.exception', '1');
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use App\Controllers\ObjectController;
use App\Core\ApiException;
use App\Core\MiddlewarePipeline;
use App\Core\Request;
use App\Core\Router;
use App\Database\QueryBuilder;
use App\Middleware\AuditMiddleware;
use App\Middleware\HmacAuthMiddleware;
use App\Middleware\JsonBodyLimitMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ReplayProtectionMiddleware;
use App\Middleware\RoutingMiddleware;
use App\Repositories\ObjectRepository;
use App\Security\ApiClientResolver;
use App\Security\HmacAuth;
use App\Security\NonceStore;
use App\Security\SignatureVerifier;
use App\Services\AuditLogService;
use App\Services\ObjectService;
use App\Services\RateLimitService;
use App\Services\PermissionService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE api_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT UNIQUE, status TEXT, secret_hash TEXT, allowed_ips TEXT)');
$db->exec('CREATE TABLE api_client_permissions (client_id INTEGER, entity_name TEXT, can_select INTEGER, can_insert INTEGER, can_update INTEGER, can_delete INTEGER, max_rows_per_select INTEGER, allowed_fields_json TEXT, allowed_filter_fields_json TEXT)');
$db->exec('CREATE TABLE api_nonces (client_id INTEGER, nonce TEXT, expires_at TEXT, UNIQUE(client_id, nonce))');
$db->exec('CREATE TABLE api_rate_limits (client_id INTEGER, bucket_key TEXT, request_count INTEGER, window_start TEXT, window_end TEXT, UNIQUE(client_id, bucket_key))');
$db->exec('CREATE TABLE api_entities (entity_name TEXT UNIQUE, table_name TEXT, primary_key_name TEXT, enabled INTEGER, schema_json TEXT)');
$db->exec('CREATE TABLE api_audit_logs (request_id TEXT, client_id INTEGER, entity_name TEXT, action_name TEXT, request_method TEXT, request_path TEXT, request_hash TEXT, ip_address TEXT, status_code INTEGER, success INTEGER, error_code TEXT, duration_ms INTEGER)');
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT, name TEXT, status TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$schema = json_encode(['fields' => ['id', 'tenant_id', 'name', 'status', 'created_at'], 'insertable' => ['tenant_id', 'name', 'status'], 'updatable' => ['name', 'status'], 'filterable' => ['id', 'tenant_id', 'status'], 'orderable' => ['id', 'created_at']], JSON_THROW_ON_ERROR);
$stmt = $db->prepare('INSERT INTO api_entities VALUES (:entity, :table_name, :primary_key, 1, :schema)');
$stmt->execute(['entity' => 'projects', 'table_name' => 'projects', 'primary_key' => 'id', 'schema' => $schema]);
$db->exec("INSERT INTO api_clients VALUES (1, 'smoke-client', 'active', 'unused', NULL)");
$db->exec("INSERT INTO api_client_permissions VALUES (1, 'projects', 1, 1, 1, 1, 10, '[\"id\",\"tenant_id\",\"name\",\"status\",\"created_at\"]', '[\"id\",\"tenant_id\",\"status\"]')");

$body = '{"where":{"tenant_id":"tenant_001"},"fields":["id","name","status"],"limit":10,"offset":0,"order_by":"id","order_dir":"asc"}';
$timestamp = gmdate('Y-m-d\TH:i:s\Z');
$nonce = 'smoke-nonce-001';
$verifier = new SignatureVerifier();
$canonical = $verifier->canonical('POST', '/api/v1/projects/select', $timestamp, $nonce, $body);
$signature = hash_hmac('sha256', $canonical, 'smoke-secret');
$request = new Request('POST', '/api/v1/projects/select', $body, ['x-client-id' => 'smoke-client', 'x-timestamp' => $timestamp, 'x-nonce' => $nonce, 'x-signature' => $signature, 'content-type' => 'application/json'], '127.0.0.1');
$client = (new HmacAuth(new ApiClientResolver($db, ['smoke-client' => 'smoke-secret']), $verifier))->authenticate($request);
assert($client['id'] === 1);
$nonces = new NonceStore($db);
assert($nonces->claim(1, $nonce, $request->attribute('timestamp')));
assert(!$nonces->claim(1, $nonce, $request->attribute('timestamp')));

$registry = new SchemaRegistry($db);
$validator = new RequestValidator();
$permissions = new PermissionService($db);
$objects = new ObjectRepository($db, new QueryBuilder(), $registry);
$entity = $registry->get('projects');
$insert = $validator->validate($entity, 'insert', ['data' => ['tenant_id' => 'tenant_001', 'name' => 'Example', 'status' => 'active']]);
$permissions->authorize(1, 'projects', 'insert', $insert);
$id = $objects->insert('projects', $insert)['id'];
$select = $validator->validate($entity, 'select', json_decode($body, true, flags: JSON_THROW_ON_ERROR));
$permissions->authorize(1, 'projects', 'select', $select);
assert($objects->select('projects', $select)[0]['name'] === 'Example');
$update = $validator->validate($entity, 'update', ['where' => ['id' => $id, 'tenant_id' => 'tenant_001'], 'data' => ['status' => 'archived']]);
assert($objects->update('projects', $update)['affected_rows'] === 1);
$delete = $validator->validate($entity, 'delete', ['where' => ['id' => $id, 'tenant_id' => 'tenant_001']]);
assert($objects->delete('projects', $delete)['affected_rows'] === 1);
$request->setAttribute('request_id', 'smoke-request');
$request->setAttribute('client', $client);
$request->setAttribute('entity', 'projects');
$request->setAttribute('action', 'select');
(new AuditLogService($db))->write($request, 200, true, null, 1);
assert((int) $db->query('SELECT COUNT(*) FROM api_audit_logs')->fetchColumn() === 1);

try {
    $validator->validate($entity, 'delete', ['where' => []]);
    assert(false, 'delete without where should fail');
} catch (ApiException $exception) {
    assert($exception->errorCode === 'REQUEST_INVALID_WHERE');
}


$pipelineBody = '{"where":{"tenant_id":"tenant_001"},"fields":["id","name","status"],"limit":10,"offset":0,"order_by":"id","order_dir":"asc"}';
$pipelineTimestamp = gmdate('Y-m-d\TH:i:s\Z');
$pipelineNonce = 'pipeline-nonce-001';
$pipelineSignature = hash_hmac('sha256', $verifier->canonical('POST', '/api/v1/projects/select', $pipelineTimestamp, $pipelineNonce, $pipelineBody), 'smoke-secret');
$pipelineRequest = new Request('POST', '/api/v1/projects/select', $pipelineBody, ['x-client-id' => 'smoke-client', 'x-timestamp' => $pipelineTimestamp, 'x-nonce' => $pipelineNonce, 'x-signature' => $pipelineSignature, 'content-type' => 'application/json'], '127.0.0.1');
$pipelineRequest->setAttribute('request_id', 'pipeline-request');
$pipeline = new MiddlewarePipeline([
    new AuditMiddleware(new AuditLogService($db)),
    new RoutingMiddleware(new Router()),
    new JsonBodyLimitMiddleware(),
    new HmacAuthMiddleware(new HmacAuth(new ApiClientResolver($db, ['smoke-client' => 'smoke-secret']), $verifier)),
    new ReplayProtectionMiddleware(new NonceStore($db)),
    new RateLimitMiddleware(new RateLimitService($db, 10)),
    new PermissionMiddleware($registry, $validator, $permissions),
]);
$response = $pipeline->handle($pipelineRequest, (new ObjectController(new ObjectService($objects)))->handle(...));
assert($response->statusCode === 200);
assert($response->payload['ok'] === true);
assert((int) $db->query('SELECT COUNT(*) FROM api_rate_limits')->fetchColumn() === 1);
assert((int) $db->query('SELECT COUNT(*) FROM api_audit_logs')->fetchColumn() === 2);

try {
    $pipeline->handle($pipelineRequest, (new ObjectController(new ObjectService($objects)))->handle(...));
    assert(false, 'replayed pipeline request should fail');
} catch (ApiException $exception) {
    assert($exception->errorCode === 'AUTH_NONCE_REPLAYED');
}
assert((int) $db->query('SELECT COUNT(*) FROM api_audit_logs')->fetchColumn() === 3);

echo "gateway HMAC, middleware, nonce, rate-limit, permission, repository, validation, and audit smoke test passed\n";
