<?php

/**
 * @file RateLimitMiddleware.php
 *
 * Applies authenticated-client or public-demo rate limits after authentication routing.
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
use App\Services\PublicDemoService;
use App\Services\RateLimitService;
use Closure;

/** Applies the appropriate limiter for authenticated clients or constrained demo traffic. */
final class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitService $rateLimits,
        private readonly ?PublicDemoService $demo = null,
    ) {
    }

    /** Enforces request capacity before allowing the operation to continue. */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attribute('is_demo') === true) {
            $this->demo?->rateLimit($request->ipAddress);

            return $next($request);
        }
        $this->rateLimits->consume($request->attribute('client')['id'], $request->ipAddress);

        return $next($request);
    }
}
