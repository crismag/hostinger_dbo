<?php

declare(strict_types=1);

use App\Controllers\ObjectController;
use App\Core\ApiException;
use App\Core\MiddlewarePipeline;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Database\Connection;
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
use App\Services\PermissionService;
use App\Services\RateLimitService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

$request = null;
try {
    $securityFile = dirname(__DIR__) . '/config/security.php';
    if (!is_readable($securityFile)) {
        throw new RuntimeException('Security configuration not found. Copy config/security.example.php to config/security.php.');
    }
    /** @var array{timestamp_window_seconds:int,max_body_bytes:int,max_requests_per_minute:int,client_secrets:array<string,string>,allow_database_secrets:bool} $security */
    $security = require $securityFile;
    $request = Request::fromGlobals($security['max_body_bytes']);
    $request->setAttribute('request_id', bin2hex(random_bytes(16)));
    $database = Connection::getInstance();
    $schemas = new SchemaRegistry($database);
    $pipeline = new MiddlewarePipeline([
        new AuditMiddleware(new AuditLogService($database)),
        new RoutingMiddleware(new Router()),
        new JsonBodyLimitMiddleware($security['max_body_bytes']),
        new HmacAuthMiddleware(new HmacAuth(
            new ApiClientResolver($database, $security['client_secrets'], $security['allow_database_secrets']),
            new SignatureVerifier(),
            $security['timestamp_window_seconds'],
        )),
        new ReplayProtectionMiddleware(new NonceStore($database, $security['timestamp_window_seconds'])),
        new RateLimitMiddleware(new RateLimitService($database, $security['max_requests_per_minute'])),
        new PermissionMiddleware($schemas, new RequestValidator(), new PermissionService($database)),
    ]);
    $controller = new ObjectController(new ObjectService(new ObjectRepository($database, new QueryBuilder(), $schemas)));
    $pipeline->handle($request, $controller->handle(...))->emit();
} catch (ApiException $exception) {
    Response::error($exception, $request !== null ? (string) $request->attribute('request_id') : '')->emit();
} catch (Throwable $exception) {
    error_log($exception->__toString());
    Response::error(new ApiException('INTERNAL_ERROR', 'Internal server error', 500), $request !== null ? (string) $request->attribute('request_id') : '')->emit();
}
