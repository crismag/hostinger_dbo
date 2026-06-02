<?php

declare(strict_types=1);

namespace App\Core;

/** Matches only the four signed database-object endpoints. */
final class Router
{
    /** @return array{entity:string,action:string} */
    public function match(Request $request): array
    {
        if ($request->method !== 'POST') {
            throw new ApiException('ROUTE_METHOD_NOT_ALLOWED', 'Only POST is allowed', 405);
        }
        if (!preg_match('#^/api/v1/([a-z][a-z0-9_]*)/(select|insert|update|delete)$#', $request->path, $matches)) {
            throw new ApiException('ROUTE_NOT_FOUND', 'Route not found', 404);
        }

        return ['entity' => $matches[1], 'action' => $matches[2]];
    }
}
