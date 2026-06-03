<?php

declare(strict_types=1);

/**
 * Service operation registry: maps `service/operation` (the URL shape
 * /api/v1/services/{service}/{operation}) to an operation key. The key must
 * exist in App\Services\Operations\OperationRegistry's compile-time allowlist —
 * handler classes are never named from config, only keys.
 *
 * Grant a client an operation by listing the key under
 * config/security.php → clients[clientId]['services'].
 *
 * This file is policy, not secrets — safe to commit.
 */
return [
    'reports' => [
        'tenant_summary' => ['handler' => 'reports.tenant_summary'],
    ],
    // TicketDesk demo operations (apps/demo-ticketdesk).
    'tickets' => [
        'agent_workload' => ['handler' => 'tickets.agent_workload'],
        'create_with_comment' => ['handler' => 'tickets.create_with_comment'],
    ],
];
