<?php

declare(strict_types=1);

namespace App\Services\Operations;

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

    /** Server-enforced scope filters for this client (e.g. ['tenant_id' => 42]); may be empty. */
    public function enforcedFilters(): array
    {
        return $this->enforcedFilters;
    }
}
