<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Security\NonceStore;
use Closure;
use DateTimeImmutable;

final class ReplayProtectionMiddleware
{
    public function __construct(private readonly NonceStore $nonces)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attribute('client');
        $timestamp = $request->attribute('timestamp');
        if (!$timestamp instanceof DateTimeImmutable || !$this->nonces->claim($client['id'], $request->attribute('nonce'), $timestamp)) {
            throw new ApiException('AUTH_NONCE_REPLAYED', 'Nonce has already been used', 401);
        }

        return $next($request);
    }
}
