<?php

/**
 * @file MiddlewarePipeline.php
 *
 * Composes request middleware around a terminal controller callback.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Core;

use Closure;

/** Executes middleware in registration order around the controller handler. */
final class MiddlewarePipeline
{
    /** @param list<object> $middleware each exposes handle(Request, Closure): Response */
    public function __construct(private readonly array $middleware)
    {
    }

    /** @param Closure(Request): Response $handler */
    public function handle(Request $request, Closure $handler): Response
    {
        $next = array_reduce(
            array_reverse($this->middleware),
            static fn (Closure $next, object $middleware): Closure =>
                static fn (Request $request): Response => $middleware->handle($request, $next),
            $handler,
        );

        return $next($request);
    }
}
