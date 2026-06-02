<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Rejects plaintext HTTP when require_https is enabled, except for local development. */
final class HttpsMiddleware
{
    private const LOCAL_IPS = ['127.0.0.1', '::1'];

    public function __construct(
        private readonly bool $requireHttps,
        private readonly bool $devMode = false,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requireHttps
            && !$this->devMode
            && !$request->secure
            && !in_array($request->ipAddress, self::LOCAL_IPS, true)
        ) {
            throw new ApiException('HTTPS_REQUIRED', 'HTTPS is required', 403);
        }

        return $next($request);
    }
}
