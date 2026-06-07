<?php

declare(strict_types=1);

namespace App\Admin;

use PDO;
use RuntimeException;

/**
 * Database-side administration of a running gateway: backend status, entity
 * enable/disable, client status, and permission grants. Pure DB operations over
 * an injected PDO (so it is testable and driver-agnostic). Secret generation and
 * config-file writes are handled by the CLI via the Installer, never here.
 */
final class AdminService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{driver:string,clients:int,entities:int,audit_logs:int} */
    public function status(): array
    {
        $count = fn (string $table): int => (int) $this->db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        return [
            'driver' => (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME),
            'clients' => $count('api_clients'),
            'entities' => $count('api_entities'),
            'audit_logs' => $count('api_audit_logs'),
        ];
    }

    /** @return list<array{entity_name:string,table_name:string,enabled:int}> */
    public function entities(): array
    {
        return $this->db->query('SELECT entity_name, table_name, enabled FROM api_entities ORDER BY entity_name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setEntityEnabled(string $name, bool $enabled): bool
    {
        $stmt = $this->db->prepare('UPDATE api_entities SET enabled = ? WHERE entity_name = ?');
        $stmt->execute([$enabled ? 1 : 0, $name]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array{client_id:string,status:string}> */
    public function clients(): array
    {
        return $this->db->query('SELECT client_id, status FROM api_clients ORDER BY client_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clientDbId(string $clientId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM api_clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function setClientStatus(string $clientId, string $status): bool
    {
        if (!in_array($status, ['active', 'disabled', 'revoked'], true)) {
            throw new RuntimeException('status must be active, disabled, or revoked');
        }
        $stmt = $this->db->prepare('UPDATE api_clients SET status = ? WHERE client_id = ?');
        $stmt->execute([$status, $clientId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Set (upsert) a client's permissions for one entity. Portable across drivers
     * (select-then-write, no dialect-specific upsert).
     *
     * @param list<string> $actions subset of select/insert/update/delete
     */
    public function setPermissions(string $clientId, string $entity, array $actions, int $maxRows = 100): void
    {
        $dbId = $this->clientDbId($clientId) ?? throw new RuntimeException("unknown client: $clientId");
        $actions = array_values(array_intersect(['select', 'insert', 'update', 'delete'], $actions));
        $params = [
            ':cid' => $dbId,
            ':entity' => $entity,
            ':s' => in_array('select', $actions, true) ? 1 : 0,
            ':i' => in_array('insert', $actions, true) ? 1 : 0,
            ':u' => in_array('update', $actions, true) ? 1 : 0,
            ':d' => in_array('delete', $actions, true) ? 1 : 0,
            ':max' => $maxRows,
        ];
        $find = $this->db->prepare('SELECT id FROM api_client_permissions WHERE client_id = ? AND entity_name = ?');
        $find->execute([$dbId, $entity]);
        if ($find->fetchColumn() !== false) {
            $this->db->prepare(
                'UPDATE api_client_permissions SET can_select = :s, can_insert = :i, can_update = :u,'
                . ' can_delete = :d, max_rows_per_select = :max WHERE client_id = :cid AND entity_name = :entity'
            )->execute($params);
        } else {
            $this->db->prepare(
                'INSERT INTO api_client_permissions (client_id, entity_name, can_select, can_insert, can_update, can_delete, max_rows_per_select)'
                . ' VALUES (:cid, :entity, :s, :i, :u, :d, :max)'
            )->execute($params);
        }
    }

    /** @return list<array<string,mixed>> */
    public function permissions(string $clientId): array
    {
        $dbId = $this->clientDbId($clientId);
        if ($dbId === null) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT entity_name, can_select, can_insert, can_update, can_delete, max_rows_per_select'
            . ' FROM api_client_permissions WHERE client_id = ? ORDER BY entity_name'
        );
        $stmt->execute([$dbId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
