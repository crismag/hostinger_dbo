<?php

/**
 * @file QueryBuilder.php
 *
 * Quotes allowlisted SQL identifiers and builds parameterized equality-filter clauses.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Database;

use App\Core\ApiException;

/** Builds SQL from registry-approved identifiers and separately bound values. */
final class QueryBuilder
{
    /**
     * Quotes a registry-controlled identifier after enforcing a conservative SQL-safe format.
     */
    public function identifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new ApiException('SCHEMA_INVALID_IDENTIFIER', 'Registry contains an invalid SQL identifier', 500);
        }

        return '`' . $identifier . '`';
    }

    /**
     * Builds equality predicates while keeping caller-provided values in bound parameters.
     *
     * @param array<string, mixed> $where
     * @param array<string, mixed> $parameters Populated with named PDO parameters.
     */
    public function where(array $where, array &$parameters): string
    {
        $clauses = [];
        foreach ($where as $field => $value) {
            $parameter = 'where_' . count($parameters);
            $clauses[] = $this->identifier($field) . ' = :' . $parameter;
            $parameters[$parameter] = $value;
        }

        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }
}
