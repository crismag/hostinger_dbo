<?php

/**
 * @file PermissionService.php
 *
 * Enforces database-backed client grants for entities, actions, fields, filters, and result limits.
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
use PDO;

/** Enforces client-specific entity action, field, filter, and row-limit grants. */
final class PermissionService
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @param array<string, mixed> $request */
    public function authorize(int $clientId, string $entity, string $action, array $request): void
    {
        $statement = $this->database->prepare('SELECT * FROM `api_client_permissions` WHERE `client_id` = :client_id AND `entity_name` = :entity LIMIT 1');
        $statement->execute(['client_id' => $clientId, 'entity' => $entity]);
        $permission = $statement->fetch();
        if (!is_array($permission) || !(bool) ($permission['can_' . $action] ?? false)) {
            throw new ApiException('PERMISSION_DENIED', 'Client is not allowed to perform this action', 403);
        }
        $fields = match ($action) {
            'select' => $this->selectOutputFields($request),
            'insert', 'update' => array_keys($request['data']),
            default => [],
        };
        $this->assertSubset($fields, $this->decodeList($permission['allowed_fields_json'] ?? null), 'field');

        // Filter-field permission covers both equality `where` keys and operator `filters`.
        $filterFields = array_keys($request['where'] ?? []);
        foreach ($request['filters'] ?? [] as $filter) {
            $filterFields[] = $filter['field'];
        }
        $this->assertSubset($filterFields, $this->decodeList($permission['allowed_filter_fields_json'] ?? null), 'filter');

        if ($action === 'select' && ($request['limit'] ?? 0) > (int) $permission['max_rows_per_select']) {
            throw new ApiException('PERMISSION_LIMIT_EXCEEDED', 'Requested limit exceeds the client permission', 403);
        }
    }

    /**
     * Output columns a select exposes: plain `fields`, or for an aggregate query
     * the group-by columns plus each aggregate's target field.
     *
     * @param array<string, mixed> $request
     * @return list<string>
     */
    private function selectOutputFields(array $request): array
    {
        if (($request['aggregate'] ?? false) !== true) {
            return $request['fields'];
        }
        $fields = $request['group_by'];
        foreach ($request['aggregates'] as $aggregate) {
            if ($aggregate['field'] !== null) {
                $fields[] = $aggregate['field'];
            }
        }

        return $fields;
    }

    /** @return list<string>|null */
    private function decodeList(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $requested
     * @param list<string>|null $allowed
     */
    private function assertSubset(array $requested, ?array $allowed, string $type): void
    {
        if ($allowed === null) {
            return;
        }
        foreach ($requested as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new ApiException('PERMISSION_FIELD_DENIED', sprintf('Client may not use %s: %s', $type, $field), 403);
            }
        }
    }
}
