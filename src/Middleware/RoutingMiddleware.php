<?php

/**
 * @file RoutingMiddleware.php
 *
 * Resolves API routes inside the middleware chain and attaches the route metadata to requests.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use Closure;

/** Resolves the fixed endpoint shape inside the audited middleware stack. */
final class RoutingMiddleware
{
    public function __construct(private readonly Router $router)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $this->router->match($request);
        $request->setAttribute('route_kind', $route['kind']);
        if ($route['kind'] === 'service') {
            $request->setAttribute('service', $route['service']);
            $request->setAttribute('operation', $route['operation']);
        } else {
            $request->setAttribute('entity', $route['entity']);
            $request->setAttribute('action', $route['action']);
        }

        return $next($request);
    }
}
