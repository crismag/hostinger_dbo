<?php

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
            'select' => $request['fields'],
            'insert', 'update' => array_keys($request['data']),
            default => [],
        };
        $this->assertSubset($fields, $this->decodeList($permission['allowed_fields_json'] ?? null), 'field');
        $this->assertSubset(array_keys($request['where'] ?? []), $this->decodeList($permission['allowed_filter_fields_json'] ?? null), 'filter');
        if ($action === 'select' && $request['limit'] > (int) $permission['max_rows_per_select']) {
            throw new ApiException('PERMISSION_LIMIT_EXCEEDED', 'Requested limit exceeds the client permission', 403);
        }
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

    /** @param array<mixed> $requested @param list<string>|null $allowed */
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
