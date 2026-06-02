<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Validation\EntitySchema;

/** Requires update/delete to target the primary key so bulk mutations cannot slip through. */
final class MutationGuardService
{
    /** @param array<string, array{allow_bulk_updates?:bool}> $clientConfig keyed by client_id */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $clientConfig = [],
    ) {
    }

    /** @param array<string, mixed> $where */
    public function assert(string $clientId, string $action, EntitySchema $schema, array $where): void
    {
        if (!$this->enabled || !in_array($action, ['update', 'delete'], true)) {
            return;
        }
        if ($this->clientConfig[$clientId]['allow_bulk_updates'] ?? false) {
            return;
        }
        if (!array_key_exists($schema->primaryKey, $where) || $where[$schema->primaryKey] === null || $where[$schema->primaryKey] === '') {
            throw new ApiException('RESTRICTIVE_WHERE_REQUIRED', 'Update and delete must filter by the primary key', 422);
        }
    }
}
