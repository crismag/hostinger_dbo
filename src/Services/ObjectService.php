<?php

/**
 * @file ObjectService.php
 *
 * Dispatches validated CRUD operation names to the object repository.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Repositories\ObjectRepository;
use App\Validation\EntitySchema;

/** Delegates validated object actions to the PDO repository. */
final class ObjectService
{
    public function __construct(private readonly ObjectRepository $objects)
    {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function execute(EntitySchema $schema, string $action, array $request): array
    {
        return match ($action) {
            'select' => ($request['aggregate'] ?? false) === true
                ? $this->objects->aggregate($schema->entity, $request)
                : $this->objects->select($schema->entity, $request),
            'insert' => $this->objects->insert($schema->entity, $request),
            'update' => $this->objects->update($schema->entity, $request),
            'delete' => $this->objects->delete($schema->entity, $request),
            default => throw new ApiException('ACTION_INVALID', 'Unsupported action'),
        };
    }
}
