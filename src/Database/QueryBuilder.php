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

    /**
     * Builds a WHERE clause from equality pairs plus operator filters (e.g. LIKE),
     * combined with AND. Identifiers are quoted; every value is bound.
     *
     * @param array<string, mixed> $where Equality pairs (field => value).
     * @param list<array{field:string,op:string,value:mixed}> $filters Operator predicates.
     * @param array<string, mixed> $parameters Populated with named PDO parameters.
     */
    public function filterClause(array $where, array $filters, array &$parameters): string
    {
        $clauses = [];
        foreach ($where as $field => $value) {
            $parameter = 'p_' . count($parameters);
            $clauses[] = $this->identifier($field) . ' = :' . $parameter;
            $parameters[$parameter] = $value;
        }
        foreach ($filters as $filter) {
            $parameter = 'p_' . count($parameters);
            $clauses[] = $this->predicate($filter['field'], $filter['op'], ':' . $parameter);
            $parameters[$parameter] = $filter['value'];
        }

        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    /** Emits a single parameterized predicate for an allowlisted operator. */
    private function predicate(string $field, string $op, string $placeholder): string
    {
        $column = $this->identifier($field);

        return match ($op) {
            'eq' => $column . ' = ' . $placeholder,
            'like' => $column . ' LIKE ' . $placeholder,
            default => throw new ApiException('REQUEST_INVALID_OPERATOR', 'Unsupported filter operator: ' . $op),
        };
    }

    /**
     * Builds the SELECT list and GROUP BY clause for an aggregate query from
     * validated, allowlisted group-by columns and aggregate specifications.
     *
     * @param list<string> $groupBy
     * @param list<array{fn:string,field:?string,as:string}> $aggregates
     * @return array{select:string,group:string}
     */
    public function aggregateSelect(array $groupBy, array $aggregates): array
    {
        $select = array_map($this->identifier(...), $groupBy);
        foreach ($aggregates as $aggregate) {
            $select[] = $this->aggregateExpression($aggregate['fn'], $aggregate['field']) . ' AS ' . $this->identifier($aggregate['as']);
        }
        $group = $groupBy === [] ? '' : ' GROUP BY ' . implode(', ', array_map($this->identifier(...), $groupBy));

        return ['select' => implode(', ', $select), 'group' => $group];
    }

    /** Emits an allowlisted aggregate function call over a quoted column (or COUNT(*)). */
    private function aggregateExpression(string $fn, ?string $field): string
    {
        $function = match ($fn) {
            'count' => 'COUNT',
            'sum' => 'SUM',
            'avg' => 'AVG',
            'min' => 'MIN',
            'max' => 'MAX',
            default => throw new ApiException('REQUEST_INVALID_AGGREGATE', 'Unsupported aggregate function: ' . $fn),
        };
        if ($field === null) {
            // Only COUNT may omit a target column (COUNT(*)).
            if ($fn !== 'count') {
                throw new ApiException('REQUEST_INVALID_AGGREGATE', $fn . ' requires a field', 400);
            }

            return 'COUNT(*)';
        }

        return $function . '(' . $this->identifier($field) . ')';
    }
}
