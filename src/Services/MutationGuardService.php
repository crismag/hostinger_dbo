<?php

/**
 * @file MutationGuardService.php
 *
 * Prevents accidental broad updates and deletes unless an explicit client policy permits them.
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
        $client = $this->clientConfig[$clientId] ?? [];
        if (($client['allow_bulk_updates'] ?? false) === true) {
            return;
        }
        if (!array_key_exists($schema->primaryKey, $where) || $where[$schema->primaryKey] === null || $where[$schema->primaryKey] === '') {
            throw new ApiException('RESTRICTIVE_WHERE_REQUIRED', 'Update and delete must filter by the primary key', 422);
        }
    }
}
