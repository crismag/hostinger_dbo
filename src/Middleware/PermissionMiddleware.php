<?php

/**
 * @file PermissionMiddleware.php
 *
 * Validates request bodies and applies permissions, tenant scope, and mutation safeguards.
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

use App\Core\Request;
use App\Core\Response;
use App\Services\MutationGuardService;
use App\Services\PermissionService;
use App\Services\PublicDemoService;
use App\Services\ScopeEnforcementService;
use App\Validation\RequestValidator;
use App\Validation\SchemaRegistry;
use Closure;

/** Loads registry metadata, validates input, then enforces grants, scope, and mutation safety. */
final class PermissionMiddleware
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly RequestValidator $validator,
        private readonly PermissionService $permissions,
        private readonly ?ScopeEnforcementService $scope = null,
        private readonly ?MutationGuardService $mutationGuard = null,
        private readonly ?PublicDemoService $demo = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $entity = (string) $request->attribute('entity');
        $action = (string) $request->attribute('action');
        $schema = $this->schemas->get($entity);

        if ($request->attribute('is_demo') === true && $this->demo !== null) {
            $constrained = $this->demo->constrain($entity, $action, $request->json());
            $validated = $this->validator->validate($schema, $action, $constrained);
            $request->setAttribute('schema', $schema);
            $request->setAttribute('validated', $validated);

            return $next($request);
        }

        $client = $request->attribute('client');
        $validated = $this->validator->validate($schema, $action, $request->json());
        $this->permissions->authorize($client['id'], $entity, $action, $validated);

        // Enforce server-controlled scope after authorizing the caller's own fields.
        if ($this->scope !== null) {
            $validated = $this->scope->apply((string) $client['client_id'], $action, $validated);
        }
        if ($this->mutationGuard !== null) {
            $this->mutationGuard->assert((string) $client['client_id'], $action, $schema, $validated['where'] ?? []);
        }

        $request->setAttribute('schema', $schema);
        $request->setAttribute('validated', $validated);

        return $next($request);
    }
}
