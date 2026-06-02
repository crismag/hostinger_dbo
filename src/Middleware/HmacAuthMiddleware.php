<?php

/**
 * @file HmacAuthMiddleware.php
 *
 * Authenticates signed traffic while optionally routing unsigned requests into constrained demo mode.
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
use App\Security\HmacAuth;
use App\Services\PublicDemoService;
use Closure;

/** Authenticates signed requests; unsigned requests fall through to the public demo when enabled. */
final class HmacAuthMiddleware
{
    public function __construct(
        private readonly HmacAuth $auth,
        private readonly ?PublicDemoService $demo = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('x-signature');
        $signed = $signature !== null && trim($signature) !== '';

        if (!$signed) {
            if ($this->demo !== null && $this->demo->isEnabled()) {
                $request->setAttribute('is_demo', true);
                $request->setAttribute('client', ['id' => null, 'client_id' => '__public_demo__', 'demo' => true]);

                return $next($request);
            }
            throw new ApiException('AUTHENTICATION_FAILED', 'Authentication failed', 401);
        }

        try {
            $request->setAttribute('client', $this->auth->authenticate($request));
        } catch (ApiException $exception) {
            // Unify every signed-auth failure so callers cannot enumerate clients; keep detail server-side.
            error_log(sprintf(
                'authentication failure [%s] client_id=%s ip=%s: %s',
                $exception->errorCode,
                (string) $request->header('x-client-id'),
                (string) $request->ipAddress,
                $exception->getMessage(),
            ));
            throw new ApiException('AUTHENTICATION_FAILED', 'Authentication failed', 401);
        }

        return $next($request);
    }
}
