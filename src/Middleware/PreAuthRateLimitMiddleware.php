<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Security\FilesystemRateLimiter;
use Closure;

/** IP-keyed abuse gate that runs before authentication and never touches the database. */
final class PreAuthRateLimitMiddleware
{
    /** @param array{enabled?:bool,minute_limit?:int,hour_limit?:int} $config */
    public function __construct(
        private readonly FilesystemRateLimiter $limiter,
        private readonly array $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!($this->config['enabled'] ?? false)) {
            return $next($request);
        }
        $limits = [
            'minute' => (int) ($this->config['minute_limit'] ?? 30),
            'hour' => (int) ($this->config['hour_limit'] ?? 500),
        ];
        $clientIp = $request->ipAddress ?? 'unknown';
        if (!$this->limiter->allow('preauth:' . $clientIp, $limits)) {
            throw new ApiException('RATE_LIMITED', 'Too many requests', 429);
        }

        return $next($request);
    }
}
