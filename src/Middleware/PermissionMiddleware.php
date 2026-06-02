<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\PermissionService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;
use Closure;

/** Loads registry metadata, validates input, then checks client grants. */
final class PermissionMiddleware
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly RequestValidator $validator,
        private readonly PermissionService $permissions,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $entity = (string) $request->attribute('entity');
        $action = (string) $request->attribute('action');
        $schema = $this->schemas->get($entity);
        $validated = $this->validator->validate($schema, $action, $request->json());
        $this->permissions->authorize($request->attribute('client')['id'], $entity, $action, $validated);
        $request->setAttribute('schema', $schema);
        $request->setAttribute('validated', $validated);

        return $next($request);
    }
}
