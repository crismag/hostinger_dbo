<?php

/**
 * @file SchemaRegistry.php
 *
 * Loads enabled entity metadata and allowlists from the gateway registry tables.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use PDO;

/** Loads enabled entity allowlists from api_entities. */
final class SchemaRegistry
{
    /** @var array<string, EntitySchema> */
    private array $cache = [];

    public function __construct(private readonly PDO $database)
    {
    }

    public function get(string $entity): EntitySchema
    {
        if (isset($this->cache[$entity])) {
            return $this->cache[$entity];
        }
        $statement = $this->database->prepare(
            'SELECT `entity_name`, `table_name`, `primary_key_name`, `schema_json` FROM `api_entities` '
            . 'WHERE `entity_name` = :entity AND `enabled` = TRUE LIMIT 1'
        );
        $statement->execute(['entity' => $entity]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException('ENTITY_NOT_FOUND', 'Unknown or disabled entity', 404);
        }
        $schema = json_decode((string) $row['schema_json'], true);
        if (!is_array($schema)) {
            throw new ApiException('SCHEMA_INVALID', 'Entity schema is invalid', 500);
        }

        return $this->cache[$entity] = new EntitySchema(
            (string) $row['entity_name'],
            (string) $row['table_name'],
            (string) $row['primary_key_name'],
            self::stringList($schema, 'fields'),
            self::stringList($schema, 'insertable'),
            self::stringList($schema, 'updatable'),
            self::stringList($schema, 'filterable'),
            self::stringList($schema, 'orderable'),
            self::stringList($schema, 'searchable', true),
            self::stringList($schema, 'groupable', true),
            self::stringList($schema, 'aggregatable', true),
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @param bool $optional When true, a missing key yields an empty list (backward compatible).
     * @return list<string>
     */
    private static function stringList(array $schema, string $key, bool $optional = false): array
    {
        $values = $schema[$key] ?? null;
        if ($values === null && $optional) {
            return [];
        }
        if (!is_array($values) || !array_is_list($values)) {
            throw new ApiException('SCHEMA_INVALID', 'Entity schema is invalid', 500);
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new ApiException('SCHEMA_INVALID', 'Entity schema is invalid', 500);
            }
        }

        return $values;
    }
}
