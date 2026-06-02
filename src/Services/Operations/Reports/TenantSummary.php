<?php

declare(strict_types=1);

namespace App\Services\Operations\Reports;

use App\Services\Operations\ServiceContext;
use App\Services\Operations\ServiceOperation;
use PDO;

/**
 * Reference report operation: projects + users per tenant via a JOIN and
 * aggregates — the kind of multi-table query the generic gateway deliberately
 * does not expose. The query shape is fixed here; only `limit` comes from the
 * caller and is bound as a parameter. Portable across MySQL and SQLite.
 */
final class TenantSummary implements ServiceOperation
{
    public function inputSpec(): array
    {
        return ['limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 500]];
    }

    public function execute(array $input, ServiceContext $context): array
    {
        $limit = (int) ($input['limit'] ?? 50);
        $sql = 'SELECT p.`tenant_id` AS tenant_id,'
            . ' COUNT(DISTINCT p.`id`) AS projects,'
            . ' COUNT(DISTINCT u.`id`) AS users'
            . ' FROM `projects` p'
            . ' LEFT JOIN `users` u ON u.`tenant_id` = p.`tenant_id`'
            . ' GROUP BY p.`tenant_id`'
            . ' ORDER BY projects DESC, p.`tenant_id` ASC'
            . ' LIMIT :limit';
        $statement = $context->pdo()->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
