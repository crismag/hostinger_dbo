<?php

/**
 * @file EntitySchema.php
 *
 * Carries allowlisted table metadata loaded from the entity registry.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;

/** Registry-controlled table metadata and operation allowlists. */
final class EntitySchema
{
    /**
     * @param list<string> $fields
     * @param list<string> $insertable
     * @param list<string> $updatable
     * @param list<string> $filterable
     * @param list<string> $orderable
     */
    public function __construct(
        public readonly string $entity,
        public readonly string $table,
        public readonly string $primaryKey,
        public readonly array $fields,
        public readonly array $insertable,
        public readonly array $updatable,
        public readonly array $filterable,
        public readonly array $orderable,
    ) {
        foreach (array_merge([$table, $primaryKey], $fields, $insertable, $updatable, $filterable, $orderable) as $identifier) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
                throw new ApiException('SCHEMA_INVALID_IDENTIFIER', 'Entity registry contains an invalid identifier', 500);
            }
        }
    }
}
