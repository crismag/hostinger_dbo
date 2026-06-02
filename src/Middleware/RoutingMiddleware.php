<?php

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
        $request->setAttribute('entity', $route['entity']);
        $request->setAttribute('action', $route['action']);

        return $next($request);
    }
}
