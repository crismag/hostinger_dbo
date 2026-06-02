<?php

/**
 * @file ObjectController.php
 *
 * Adapts validated object-operation requests into service calls and JSON HTTP responses.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ObjectService;
use App\Validation\EntitySchema;

/** Final HTTP adapter for already authenticated and validated object actions. */
final class ObjectController
{
    public function __construct(private readonly ObjectService $objects)
    {
    }

    public function handle(Request $request): Response
    {
        /** @var EntitySchema $schema */
        $schema = $request->attribute('schema');
        /** @var array<string, mixed> $validated */
        $validated = $request->attribute('validated');
        $action = (string) $request->attribute('action');
        $status = $action === 'insert' ? 201 : 200;
        $data = $this->objects->execute($schema, $action, $validated);
        $count = is_array($data) && array_is_list($data)
            ? count($data)
            : (is_array($data) && isset($data['affected_rows']) ? (int) $data['affected_rows'] : 1);
        $meta = ['operation' => $action, 'entity' => $schema->entity, 'count' => $count];

        return Response::success($data, (string) $request->attribute('request_id'), $status, $meta);
    }
}
