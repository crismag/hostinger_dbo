<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ObjectRepository;
use App\Validation\EntitySchema;

/** Delegates validated object actions to the PDO repository. */
final class ObjectService
{
    public function __construct(private readonly ObjectRepository $objects)
    {
    }

    /** @param array<string, mixed> $request */
    public function execute(EntitySchema $schema, string $action, array $request): array
    {
        return match ($action) {
            'select' => $this->objects->select($schema->entity, $request),
            'insert' => $this->objects->insert($schema->entity, $request),
            'update' => $this->objects->update($schema->entity, $request),
            'delete' => $this->objects->delete($schema->entity, $request),
        };
    }
}
