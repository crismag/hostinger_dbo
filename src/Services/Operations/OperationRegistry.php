<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\ApiException;
use App\Services\Operations\Reports\TenantSummary;
use App\Services\Operations\Tickets\AgentWorkload;
use App\Services\Operations\Tickets\CreateWithComment;

/**
 * Resolves a service operation key to its handler class through a FIXED,
 * compile-time allowlist. Handler classes are never derived from configuration
 * or database strings — this is the code-execution counterpart of the project's
 * SQL-identifier allowlisting. To add an operation, register its class here.
 */
final class OperationRegistry
{
    /** @var array<string, class-string<ServiceOperation>> */
    private const MAP = [
        'reports.tenant_summary' => TenantSummary::class,
        'tickets.agent_workload' => AgentWorkload::class,
        'tickets.create_with_comment' => CreateWithComment::class,
    ];

    public function has(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    public function resolve(string $key): ServiceOperation
    {
        $class = self::MAP[$key] ?? null;
        if ($class === null) {
            throw new ApiException('SERVICE_OPERATION_NOT_FOUND', 'No such service operation', 404);
        }
        // The MAP is typed class-string<ServiceOperation>, so this is guaranteed.
        return new $class();
    }
}
