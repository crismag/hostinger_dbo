<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\ApiException;

/** Builds SQL from registry-approved identifiers and separately bound values. */
final class QueryBuilder
{
    public function identifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new ApiException('SCHEMA_INVALID_IDENTIFIER', 'Registry contains an invalid SQL identifier', 500);
        }

        return '`' . $identifier . '`';
    }

    /** @param array<string, mixed> $where @param array<string, mixed> $parameters */
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
