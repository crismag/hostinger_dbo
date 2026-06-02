<?php

/**
 * @file index.php
 *
 * Bootstraps the HTTP gateway, constructs its middleware pipeline, and emits the final JSON response.
 *
 * Creation Date: 2026-06-02
 * Inputs: HTTP request data plus runtime configuration files.
 * Outputs: Emits an HTTP response.
 * Usage: Configure the web server document root to public/; requests are routed to this front controller.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

use App\Controllers\ObjectController;
use App\Controllers\ServiceController;
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
use App\Services\Operations\OperationRegistry;
use App\Services\MutationGuardService;
use App\Services\ObjectService;
use App\Services\PermissionService;
use App\Services\PublicDemoService;
use App\Services\RateLimitService;
use App\Services\ScopeEnforcementService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;

// Prefer Composer's autoloader when the project was installed via Composer; fall
// back to a built-in PSR-4 loader so the gateway stays dependency-free and runs
// on hosts where Composer is unavailable.
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
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
}

// ---------------------------------------------------------------------------
// Bootstrap request metadata and deployment configuration.
$request = null;
try {
    $securityFile = dirname(__DIR__) . '/config/security.php';
    if (!is_readable($securityFile)) {
        throw new RuntimeException('Security configuration not found. Copy config/security.example.php to config/security.php.');
    }
    /** @var array<string, mixed> $security */
    $security = require $securityFile;

    // Apply an environment profile (dev/demo/prod) when APP_ENV is set. Opt-in:
    // with no APP_ENV, config/security.php is used unchanged.
    $appEnv = getenv('APP_ENV');
    if ($appEnv !== false && $appEnv !== '') {
        $profilesFile = dirname(__DIR__) . '/config/profiles.php';
        $profiles = is_readable($profilesFile) ? require $profilesFile : null;
        $security = App\Config\Profiles::apply($security, (string) $appEnv, is_array($profiles) ? $profiles : null);
    }

    $trustedProxies = array_values(array_filter(
        (array) ($security['trusted_proxies'] ?? []),
        static fn (mixed $proxy): bool => is_string($proxy) && trim($proxy) !== ''
    ));
    $request = Request::fromGlobals((int) $security['max_body_bytes'], $trustedProxies);
    $request->setAttribute('request_id', bin2hex(random_bytes(16)));

    // Hardening configuration with backward-compatible defaults.
    $preAuthConfig = (array) ($security['pre_auth_rate_limit'] ?? ['enabled' => false]);
    $auditConfig = (array) ($security['audit'] ?? ['mode' => 'authenticated_only']);
    $demoConfig = (array) ($security['public_demo'] ?? ['enabled' => false]);
    $clientConfig = (array) ($security['clients'] ?? []);
    $mutationConfig = (array) ($security['mutation_guard'] ?? ['enabled' => false]);
    $scopeViolation = (string) ($security['tenant_scope']['on_violation'] ?? 'reject');
    $storageDir = (string) ($preAuthConfig['storage_dir'] ?? (sys_get_temp_dir() . '/dbo_gateway_ratelimit'));

    // Reject abusive traffic before opening a database connection.
    $limiter = new FilesystemRateLimiter($storageDir);
    (new MiddlewarePipeline([
        new HttpsMiddleware((bool) ($security['require_https'] ?? true), (bool) ($security['dev_mode'] ?? false)),
        new PreAuthRateLimitMiddleware($limiter, $preAuthConfig),
    ]))->handle($request, static fn (Request $request): Response => new Response(['ok' => true]));

    $database = Connection::getInstance();
    $schemas = new SchemaRegistry($database);
    $demo = new PublicDemoService($demoConfig, $limiter);

    // Process the authenticated or constrained-demo operation through the main policy stack.
    $pipeline = new MiddlewarePipeline([
        new RoutingMiddleware(new Router()),
        new JsonBodyLimitMiddleware((int) $security['max_body_bytes']),
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
    // Terminal dispatch: object operations vs named service operations, chosen
    // by the route kind. Both run behind the single pipeline above.
    $objectController = new ObjectController(new ObjectService(new ObjectRepository($database, new QueryBuilder(), $schemas)));
    $servicesFile = dirname(__DIR__) . '/config/services.php';
    $servicesConfig = is_readable($servicesFile) ? (array) require $servicesFile : [];
    $serviceController = new ServiceController(new OperationRegistry(), $servicesConfig, $clientConfig, $database);

    $dispatch = static function (Request $request) use ($objectController, $serviceController): Response {
        return $request->attribute('route_kind') === 'service'
            ? $serviceController->handle($request)
            : $objectController->handle($request);
    };
    $pipeline->handle($request, $dispatch)->emit();
// Convert expected domain failures and unexpected errors into safe JSON envelopes.
} catch (ApiException $exception) {
    Response::error($exception, $request !== null ? (string) $request->attribute('request_id') : '')->emit();
} catch (Throwable $exception) {
    error_log($exception->__toString());
    Response::error(new ApiException('INTERNAL_ERROR', 'Internal server error', 500), $request !== null ? (string) $request->attribute('request_id') : '')->emit();
}
