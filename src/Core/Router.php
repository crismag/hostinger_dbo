<?php

/**
 * @file Router.php
 *
 * Matches the intentionally small database-object endpoint surface and extracts route attributes.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Core;

/** Matches only the four signed database-object endpoints. */
final class Router
{
    /**
     * Resolve an allowlisted endpoint shape into a route descriptor: either
     * `{kind: 'entity', entity, action}` or `{kind: 'service', service, operation}`.
     *
     * @return array<string, string>
     */
    public function match(Request $request): array
    {
        if ($request->method !== 'POST') {
            throw new ApiException('ROUTE_METHOD_NOT_ALLOWED', 'Only POST is allowed', 405);
        }
        // Named service operations (checked first; distinct 3-segment shape).
        if (preg_match('#^/api/v1/services/([a-z][a-z0-9_]*)/([a-z][a-z0-9_]*)$#', $request->path, $matches)) {
            return ['kind' => 'service', 'service' => $matches[1], 'operation' => $matches[2]];
        }
        // Generic object operations.
        if (preg_match('#^/api/v1/([a-z][a-z0-9_]*)/(select|insert|update|delete)$#', $request->path, $matches)) {
            return ['kind' => 'entity', 'entity' => $matches[1], 'action' => $matches[2]];
        }
        throw new ApiException('ROUTE_NOT_FOUND', 'Route not found', 404);
    }
}
