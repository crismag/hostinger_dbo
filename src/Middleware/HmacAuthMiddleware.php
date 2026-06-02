<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Security\HmacAuth;
use Closure;

final class HmacAuthMiddleware
{
    public function __construct(private readonly HmacAuth $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->setAttribute('client', $this->auth->authenticate($request));

        return $next($request);
    }
}
