<?php

/**
 * @file ObjectRepository.php
 *
 * Executes registry-approved CRUD statements with quoted identifiers and prepared values.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Repositories;

use App\Core\ApiException;
use App\Database\QueryBuilder;
use App\Validation\SchemaRegistry;
use PDO;
use PDOException;

/** Executes registry-approved object operations with prepared values only. */
final class ObjectRepository extends BaseRepository
{
    public function __construct(PDO $database, private readonly QueryBuilder $queryBuilder, private readonly SchemaRegistry $schemas)
    {
        parent::__construct($database);
    }

    /**
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>> Rows matching the validated request.
     */
    public function select(string $entity, array $request): array
    {
        $schema = $this->schemas->get($entity);
        $parameters = [];
        $where = $this->queryBuilder->filterClause($request['where'], $request['filters'] ?? [], $parameters);
        $fields = implode(', ', array_map($this->queryBuilder->identifier(...), $request['fields']));
        $sql = sprintf('SELECT %s FROM %s%s ORDER BY %s %s LIMIT :limit OFFSET :offset', $fields,
            $this->queryBuilder->identifier($schema->table), $where,
            $this->queryBuilder->identifier($request['orderBy']), strtoupper($request['orderDir']));
        $statement = $this->database->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $request['limit'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $request['offset'], PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Executes a registry-approved GROUP BY / aggregate query. Identifiers come
     * only from the validated request; all filter values are bound parameters.
     *
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>> Aggregated rows.
     */
    public function aggregate(string $entity, array $request): array
    {
        $schema = $this->schemas->get($entity);
        $parameters = [];
        $where = $this->queryBuilder->filterClause($request['where'], $request['filters'] ?? [], $parameters);
        $built = $this->queryBuilder->aggregateSelect($request['group_by'], $request['aggregates']);
        $order = '';
        if ($request['orderBy'] !== null) {
            $order = ' ORDER BY ' . $this->queryBuilder->identifier($request['orderBy']) . ' ' . strtoupper($request['orderDir']);
        }
        $sql = sprintf('SELECT %s FROM %s%s%s%s LIMIT :limit OFFSET :offset',
            $built['select'], $this->queryBuilder->identifier($schema->table), $where, $built['group'], $order);
        $statement = $this->database->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $request['limit'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $request['offset'], PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, int> Identifier generated for the inserted row.
     */
    public function insert(string $entity, array $request): array
    {
        $schema = $this->schemas->get($entity);
        $fields = array_keys($request['data']);
        $columns = implode(', ', array_map($this->queryBuilder->identifier(...), $fields));
        $placeholders = implode(', ', array_map(static fn (string $field): string => ':' . $field, $fields));
        $statement = $this->database->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)',
            $this->queryBuilder->identifier($schema->table), $columns, $placeholders));
        try {
            $statement->execute($request['data']);
        } catch (PDOException $exception) {
            $this->throwConflict($exception);
        }

        return [$schema->primaryKey => (int) $this->database->lastInsertId()];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{affected_rows:int} Number of rows changed by the validated mutation.
     */
    public function update(string $entity, array $request): array
    {
        $schema = $this->schemas->get($entity);
        $parameters = [];
        $assignments = [];
        foreach ($request['data'] as $field => $value) {
            $parameter = 'data_' . count($parameters);
            $assignments[] = $this->queryBuilder->identifier($field) . ' = :' . $parameter;
            $parameters[$parameter] = $value;
        }
        $where = $this->queryBuilder->where($request['where'], $parameters);
        $statement = $this->database->prepare(sprintf('UPDATE %s SET %s%s',
            $this->queryBuilder->identifier($schema->table), implode(', ', $assignments), $where));
        try {
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            $this->throwConflict($exception);
        }

        return ['affected_rows' => $statement->rowCount()];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{affected_rows:int} Number of rows deleted by the validated mutation.
     */
    public function delete(string $entity, array $request): array
    {
        $schema = $this->schemas->get($entity);
        $parameters = [];
        $where = $this->queryBuilder->where($request['where'], $parameters);
        $statement = $this->database->prepare(sprintf('DELETE FROM %s%s',
            $this->queryBuilder->identifier($schema->table), $where));
        $statement->execute($parameters);

        return ['affected_rows' => $statement->rowCount()];
    }

    private function throwConflict(PDOException $exception): never
    {
        if ($exception->getCode() === '23000') {
            throw new ApiException('OBJECT_CONFLICT', 'Object operation conflicts with stored data', 409);
        }
        throw $exception;
    }
}
