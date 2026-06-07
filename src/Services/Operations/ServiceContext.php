<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\ApiException;
use PDO;

/**
 * Execution context handed to a service operation: the database handle, the
 * resolved client, and the client's server-enforced scope filters. Handlers
 * are trusted, developer-authored code, but should still honour the client's
 * scope (e.g. tenant_id) when querying.
 */
final class ServiceContext
{
    /**
     * @param array{id:int,client_id:string,secret:string} $client
     * @param array<string, scalar> $enforcedFilters
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $client,
        private readonly array $enforcedFilters = [],
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function clientId(): string
    {
        return $this->client['client_id'];
    }

    /**
     * Server-enforced scope filters for this client (e.g. ['tenant_id' => 42]); may be empty.
     *
     * @return array<string, scalar>
     */
    public function enforcedFilters(): array
    {
        return $this->enforcedFilters;
    }

    /**
     * Merge the client's enforced scope filters into a handler's where map. If a
     * base entry conflicts with an enforced filter, throws — so a handler cannot
     * accidentally (or be tricked into) widening its scope.
     *
     * @param array<string, scalar> $base
     * @return array<string, scalar>
     */
    public function scopedWhere(array $base = []): array
    {
        $out = $base;
        foreach ($this->enforcedFilters as $field => $value) {
            if (array_key_exists($field, $out) && (string) $out[$field] !== (string) $value) {
                throw new ApiException('TENANT_SCOPE_VIOLATION', 'Service input conflicts with the enforced scope', 403);
            }
            $out[$field] = $value;
        }
        return $out;
    }

    /**
     * Same merge+conflict check as scopedWhere(), named for the "validate the
     * caller-supplied where" intent.
     *
     * @param array<string, scalar> $inputWhere
     * @return array<string, scalar>
     */
    public function enforceScopeOrFail(array $inputWhere): array
    {
        return $this->scopedWhere($inputWhere);
    }

    /**
     * Build a parameter-bound SQL WHERE fragment (without the leading WHERE) from
     * the scoped filters, ready to AND into a handler's query. Optionally prefixes
     * columns with a table alias (e.g. 'p' produces ``p.`tenant_id` = :scope_0``).
     * Returns '' when the client has no enforced scope.
     *
     * @param array<string, scalar> $base
     * @param array<string, mixed> $params populated with bound parameters
     */
    public function bindScopedWhere(array $base, array &$params, string $columnPrefix = ''): string
    {
        $clauses = [];
        foreach ($this->scopedWhere($base) as $field => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                throw new ApiException('SCHEMA_INVALID_IDENTIFIER', 'Invalid scope identifier', 500);
            }
            $param = 'scope_' . count($params);
            $column = $columnPrefix !== '' ? $columnPrefix . '.`' . $field . '`' : '`' . $field . '`';
            $clauses[] = $column . ' = :' . $param;
            $params[$param] = $value;
        }
        return implode(' AND ', $clauses);
    }
}
