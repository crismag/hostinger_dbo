<?php

/**
 * @file RequestValidator.php
 *
 * Normalizes and validates JSON object-operation payloads before permission checks and SQL execution.
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

/** Validates object-operation JSON before repository access. */
final class RequestValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function validate(EntitySchema $schema, string $action, array $body): array
    {
        return match ($action) {
            'select' => $this->select($schema, $body),
            'insert' => $this->insert($schema, $body),
            'update' => $this->update($schema, $body),
            'delete' => $this->delete($schema, $body),
            default => throw new ApiException('ACTION_INVALID', 'Unsupported action'),
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function select(EntitySchema $schema, array $body): array
    {
        $where = $this->map($body, 'where', false);
        $this->allowed(array_keys($where), $schema->filterable, 'filter');
        $filters = $this->filters($schema, $body);

        $limit = filter_var($body['limit'] ?? 100, FILTER_VALIDATE_INT);
        $offset = filter_var($body['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $offset === false || $offset < 0) {
            throw new ApiException('REQUEST_INVALID_PAGINATION', 'limit and offset must be valid integers');
        }
        $orderDir = strtolower((string) ($body['order_dir'] ?? 'asc'));
        if (!in_array($orderDir, ['asc', 'desc'], true)) {
            throw new ApiException('REQUEST_INVALID_ORDER', 'order_dir must be asc or desc');
        }

        // GROUP BY / aggregate mode is triggered by group_by or aggregates being present.
        if (isset($body['group_by']) || isset($body['aggregates'])) {
            return $this->aggregate($schema, $body, $where, $filters, $limit, $offset, $orderDir);
        }

        $fields = $body['fields'] ?? $schema->fields;
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            throw new ApiException('REQUEST_INVALID_FIELDS', 'fields must be a non-empty array');
        }
        $this->allowed($fields, $schema->fields, 'field');
        $orderBy = (string) ($body['order_by'] ?? $schema->primaryKey);
        if (!in_array($orderBy, $schema->orderable, true)) {
            throw new ApiException('REQUEST_INVALID_ORDER', 'order_by is not allowed');
        }

        return compact('where', 'filters', 'fields', 'limit', 'offset', 'orderBy', 'orderDir') + ['aggregate' => false];
    }

    /**
     * Validates the optional operator-filter array (e.g. LIKE). Each entry is
     * {field, op, value}; the field must be registry-allowlisted for its operator
     * and the value is kept scalar (bound later as a parameter).
     *
     * @param array<string, mixed> $body
     * @return list<array{field:string,op:string,value:mixed}>
     */
    private function filters(EntitySchema $schema, array $body): array
    {
        $raw = $body['filters'] ?? [];
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ApiException('REQUEST_INVALID_FILTERS', 'filters must be a JSON array');
        }
        $filters = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['field'], $entry['op']) || !array_key_exists('value', $entry)) {
                throw new ApiException('REQUEST_INVALID_FILTERS', 'each filter requires field, op, and value');
            }
            $field = $entry['field'];
            $op = strtolower((string) $entry['op']);
            $value = $entry['value'];
            if (!in_array($op, ['eq', 'like'], true)) {
                throw new ApiException('REQUEST_INVALID_OPERATOR', 'Unsupported filter operator: ' . $op);
            }
            $allowList = $op === 'like' ? $schema->searchable : $schema->filterable;
            $code = $op === 'like' ? 'REQUEST_FIELD_NOT_SEARCHABLE' : 'REQUEST_FIELD_NOT_ALLOWED';
            if (!is_string($field) || !in_array($field, $allowList, true)) {
                throw new ApiException($code, 'filter field is not allowed: ' . (string) $field);
            }
            if (is_array($value) || is_object($value)) {
                throw new ApiException('REQUEST_INVALID_VALUE', 'filter values must be scalar');
            }
            $filters[] = ['field' => $field, 'op' => $op, 'value' => $op === 'like' ? (string) $value : $value];
        }

        return $filters;
    }

    /**
     * Validates GROUP BY + aggregate selection on top of an already-validated
     * where/filters/pagination context. All identifiers are registry-allowlisted.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $where
     * @param list<array{field:string,op:string,value:mixed}> $filters
     * @return array<string, mixed>
     */
    private function aggregate(EntitySchema $schema, array $body, array $where, array $filters, int $limit, int $offset, string $orderDir): array
    {
        $groupBy = $body['group_by'] ?? [];
        if (!is_array($groupBy) || !array_is_list($groupBy)) {
            throw new ApiException('REQUEST_INVALID_GROUP_BY', 'group_by must be a JSON array');
        }
        foreach ($groupBy as $field) {
            if (!is_string($field) || !in_array($field, $schema->groupable, true)) {
                throw new ApiException('REQUEST_FIELD_NOT_GROUPABLE', 'field is not groupable: ' . (string) $field);
            }
        }
        $rawAggregates = $body['aggregates'] ?? [];
        if (!is_array($rawAggregates) || !array_is_list($rawAggregates) || $rawAggregates === []) {
            throw new ApiException('REQUEST_INVALID_AGGREGATE', 'aggregates must be a non-empty JSON array');
        }
        $aggregates = [];
        $outputs = $groupBy; // valid order_by targets: group columns plus aliases
        foreach ($rawAggregates as $entry) {
            if (!is_array($entry) || !isset($entry['fn'], $entry['as'])) {
                throw new ApiException('REQUEST_INVALID_AGGREGATE', 'each aggregate requires fn and as');
            }
            $fn = strtolower((string) $entry['fn']);
            if (!in_array($fn, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                throw new ApiException('REQUEST_INVALID_AGGREGATE', 'unsupported aggregate function: ' . $fn);
            }
            $as = (string) $entry['as'];
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
                throw new ApiException('REQUEST_INVALID_ALIAS', 'invalid aggregate alias: ' . $as);
            }
            $field = $entry['field'] ?? null;
            if ($field !== null) {
                if (!is_string($field) || !in_array($field, $schema->aggregatable, true)) {
                    throw new ApiException('REQUEST_FIELD_NOT_AGGREGATABLE', 'field is not aggregatable: ' . (string) $field);
                }
            } elseif ($fn !== 'count') {
                throw new ApiException('REQUEST_INVALID_AGGREGATE', $fn . ' requires a field');
            }
            $aggregates[] = ['fn' => $fn, 'field' => $field, 'as' => $as];
            $outputs[] = $as;
        }
        $orderBy = null;
        if (isset($body['order_by'])) {
            $orderBy = (string) $body['order_by'];
            if (!in_array($orderBy, $outputs, true)) {
                throw new ApiException('REQUEST_INVALID_ORDER', 'order_by must be a group_by column or an aggregate alias');
            }
        }

        return [
            'aggregate' => true,
            'where' => $where,
            'filters' => $filters,
            'group_by' => $groupBy,
            'aggregates' => $aggregates,
            'limit' => $limit,
            'offset' => $offset,
            'orderBy' => $orderBy,
            'orderDir' => $orderDir,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function insert(EntitySchema $schema, array $body): array
    {
        $data = $this->map($body, 'data', true);
        $this->allowed(array_keys($data), $schema->insertable, 'insert field');

        return compact('data');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function update(EntitySchema $schema, array $body): array
    {
        $where = $this->map($body, 'where', true);
        $data = $this->map($body, 'data', true);
        $this->allowed(array_keys($where), $schema->filterable, 'filter');
        $this->allowed(array_keys($data), $schema->updatable, 'update field');

        return compact('where', 'data');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function delete(EntitySchema $schema, array $body): array
    {
        $where = $this->map($body, 'where', true);
        $this->allowed(array_keys($where), $schema->filterable, 'filter');

        return compact('where');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function map(array $body, string $key, bool $required): array
    {
        $value = $body[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || ($required && $value === [])) {
            throw new ApiException('REQUEST_INVALID_' . strtoupper($key), $key . ' must be a non-empty JSON object');
        }
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                throw new ApiException('REQUEST_INVALID_VALUE', 'Nested values are not supported');
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $requested
     * @param list<string> $allowed
     */
    private function allowed(array $requested, array $allowed, string $label): void
    {
        foreach ($requested as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new ApiException('REQUEST_FIELD_NOT_ALLOWED', sprintf('%s is not allowed: %s', $label, (string) $field));
            }
        }
    }
}
