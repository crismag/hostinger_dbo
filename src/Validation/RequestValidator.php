<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;

/** Validates object-operation JSON before repository access. */
final class RequestValidator
{
    /** @param array<string, mixed> $body @return array<string, mixed> */
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

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function select(EntitySchema $schema, array $body): array
    {
        $where = $this->map($body, 'where', false);
        $this->allowed(array_keys($where), $schema->filterable, 'filter');
        $fields = $body['fields'] ?? $schema->fields;
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            throw new ApiException('REQUEST_INVALID_FIELDS', 'fields must be a non-empty array');
        }
        $this->allowed($fields, $schema->fields, 'field');
        $limit = filter_var($body['limit'] ?? 100, FILTER_VALIDATE_INT);
        $offset = filter_var($body['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $offset === false || $offset < 0) {
            throw new ApiException('REQUEST_INVALID_PAGINATION', 'limit and offset must be valid integers');
        }
        $orderBy = (string) ($body['order_by'] ?? $schema->primaryKey);
        if (!in_array($orderBy, $schema->orderable, true)) {
            throw new ApiException('REQUEST_INVALID_ORDER', 'order_by is not allowed');
        }
        $orderDir = strtolower((string) ($body['order_dir'] ?? 'asc'));
        if (!in_array($orderDir, ['asc', 'desc'], true)) {
            throw new ApiException('REQUEST_INVALID_ORDER', 'order_dir must be asc or desc');
        }

        return compact('where', 'fields', 'limit', 'offset', 'orderBy', 'orderDir');
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function insert(EntitySchema $schema, array $body): array
    {
        $data = $this->map($body, 'data', true);
        $this->allowed(array_keys($data), $schema->insertable, 'insert field');

        return compact('data');
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function update(EntitySchema $schema, array $body): array
    {
        $where = $this->map($body, 'where', true);
        $data = $this->map($body, 'data', true);
        $this->allowed(array_keys($where), $schema->filterable, 'filter');
        $this->allowed(array_keys($data), $schema->updatable, 'update field');

        return compact('where', 'data');
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function delete(EntitySchema $schema, array $body): array
    {
        $where = $this->map($body, 'where', true);
        $this->allowed(array_keys($where), $schema->filterable, 'filter');

        return compact('where');
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
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

    /** @param array<mixed> $requested @param list<string> $allowed */
    private function allowed(array $requested, array $allowed, string $label): void
    {
        foreach ($requested as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new ApiException('REQUEST_FIELD_NOT_ALLOWED', sprintf('%s is not allowed: %s', $label, (string) $field));
            }
        }
    }
}
