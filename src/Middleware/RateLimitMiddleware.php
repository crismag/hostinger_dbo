<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimitService;
use Closure;

final class RateLimitMiddleware
{
    public function __construct(private readonly RateLimitService $rateLimits)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->rateLimits->consume($request->attribute('client')['id'], $request->ipAddress);

        return $next($request);
    }
}
