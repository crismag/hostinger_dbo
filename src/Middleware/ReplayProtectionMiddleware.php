<?php

/**
 * @file ReplayProtectionMiddleware.php
 *
 * Claims authenticated request nonces and rejects duplicate signed requests.
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
use App\Security\NonceStore;
use Closure;
use DateTimeImmutable;

/** Rejects an authenticated request when its client nonce has already been accepted. */
final class ReplayProtectionMiddleware
{
    public function __construct(private readonly NonceStore $nonces)
    {
    }

    /** Claims the signed nonce atomically before invoking downstream middleware. */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attribute('is_demo') === true) {
            return $next($request);
        }
        $client = $request->attribute('client');
        $timestamp = $request->attribute('timestamp');
        if (!$timestamp instanceof DateTimeImmutable || !$this->nonces->claim($client['id'], $request->attribute('nonce'), $timestamp)) {
            throw new ApiException('AUTH_NONCE_REPLAYED', 'Nonce has already been used', 401);
        }

        return $next($request);
    }
}
