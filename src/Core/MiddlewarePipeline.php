<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/** Executes middleware in registration order around the controller handler. */
final class MiddlewarePipeline
{
    /** @param list<object{handle(Request, Closure): Response}> $middleware */
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
