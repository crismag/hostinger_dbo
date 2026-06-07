<?php

/**
 * @file HttpsMiddleware.php
 *
 * Rejects insecure requests when HTTPS enforcement is enabled.
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

        // Tell compliant clients to stay on HTTPS (only meaningful over TLS).
        if ($request->secure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        return $next($request);
    }
}
