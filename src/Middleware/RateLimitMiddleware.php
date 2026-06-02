<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\PublicDemoService;
use App\Services\RateLimitService;
use Closure;

final class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitService $rateLimits,
        private readonly ?PublicDemoService $demo = null,
    ) {
    }

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
