<?php

declare(strict_types=1);

namespace App\Services\Operations\Tickets;

use App\Services\Operations\ServiceContext;
use App\Services\Operations\ServiceOperation;

/**
 * Agent workload report: tickets per agent with open/pending breakdowns via a
 * JOIN and conditional aggregates — not expressible through the single-entity
 * gateway. No caller input; the query is entirely static, trusted code.
 * Portable across MySQL and SQLite.
 */
final class AgentWorkload implements ServiceOperation
{
    public function inputSpec(): array
    {
        return [];
    }

    public function execute(array $input, ServiceContext $context): array
    {
        $sql = 'SELECT a.`id` AS agent_id, a.`name` AS agent,'
            . ' COUNT(t.`id`) AS total,'
            . " SUM(CASE WHEN t.`status` = 'open' THEN 1 ELSE 0 END) AS open,"
            . " SUM(CASE WHEN t.`status` = 'pending' THEN 1 ELSE 0 END) AS pending"
            . ' FROM `agents` a'
            . ' LEFT JOIN `tickets` t ON t.`agent_id` = a.`id`'
            . ' GROUP BY a.`id`, a.`name`'
            . ' ORDER BY total DESC, a.`name` ASC';

        return $context->pdo()->query($sql)->fetchAll();
    }
}
