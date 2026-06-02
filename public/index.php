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
    /** @var array<string, mixed> $security */
    $security = require $securityFile;
    $request = Request::fromGlobals((int) $security['max_body_bytes']);
    $request->setAttribute('request_id', bin2hex(random_bytes(16)));
    $database = Connection::getInstance();
    $schemas = new SchemaRegistry($database);

    // Hardening configuration with backward-compatible defaults.
    $preAuthConfig = (array) ($security['pre_auth_rate_limit'] ?? ['enabled' => false]);
    $auditConfig = (array) ($security['audit'] ?? ['mode' => 'authenticated_only']);
    $demoConfig = (array) ($security['public_demo'] ?? ['enabled' => false]);
    $clientConfig = (array) ($security['clients'] ?? []);
    $mutationConfig = (array) ($security['mutation_guard'] ?? ['enabled' => false]);
    $scopeViolation = (string) ($security['tenant_scope']['on_violation'] ?? 'reject');
    $storageDir = (string) ($preAuthConfig['storage_dir'] ?? (sys_get_temp_dir() . '/dbo_gateway_ratelimit'));

    $limiter = new FilesystemRateLimiter($storageDir);
    $demo = new PublicDemoService($demoConfig, $limiter);

    $pipeline = new MiddlewarePipeline([
        new HttpsMiddleware((bool) ($security['require_https'] ?? true), (bool) ($security['dev_mode'] ?? false)),
        new RoutingMiddleware(new Router()),
        new JsonBodyLimitMiddleware((int) $security['max_body_bytes']),
        new PreAuthRateLimitMiddleware($limiter, $preAuthConfig),
        new AuditMiddleware(new AuditLogService($database, $auditConfig)),
        new HmacAuthMiddleware(new HmacAuth(
            new ApiClientResolver($database, $security['client_secrets'], (bool) $security['allow_database_secrets']),
            new SignatureVerifier(),
            (int) $security['timestamp_window_seconds'],
        ), $demo),
        new ReplayProtectionMiddleware(new NonceStore($database, (int) $security['timestamp_window_seconds'])),
        new RateLimitMiddleware(new RateLimitService($database, (int) $security['max_requests_per_minute']), $demo),
        new PermissionMiddleware(
            $schemas,
            new RequestValidator(),
            new PermissionService($database),
            new ScopeEnforcementService($clientConfig, $scopeViolation),
            new MutationGuardService((bool) ($mutationConfig['enabled'] ?? false), $clientConfig),
            $demo,
        ),
    ]);
    $controller = new ObjectController(new ObjectService(new ObjectRepository($database, new QueryBuilder(), $schemas)));
    $pipeline->handle($request, $controller->handle(...))->emit();
} catch (ApiException $exception) {
    Response::error($exception, $request !== null ? (string) $request->attribute('request_id') : '')->emit();
} catch (Throwable $exception) {
    error_log($exception->__toString());
    Response::error(new ApiException('INTERNAL_ERROR', 'Internal server error', 500), $request !== null ? (string) $request->attribute('request_id') : '')->emit();
}
