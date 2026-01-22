<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Processors;

use Hypervel\Database\Query\Builder;

class Processor
{
    /**
     * Process the results of a "select" query.
     */
    public function processSelect(Builder $query, array $results): array
    {
        return $results;
    }

    /**
     * Process an "insert get ID" query.
     */
    public function processInsertGetId(Builder $query, string $sql, array $values, ?string $sequence = null): int|string
    {
        $query->getConnection()->insert($sql, $values);

        $id = $query->getConnection()->getPdo()->lastInsertId($sequence);

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * Process the results of a schemas query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, path: string|null, default: bool}>
     */
    public function processSchemas(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'path' => $result->path ?? null, // SQLite Only...
                'default' => (bool) $result->default,
            ];
        }, $results);
    }

    /**
     * Process the results of a tables query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function processTables(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema ?? null,
                'schema_qualified_name' => isset($result->schema) ? $result->schema.'.'.$result->name : $result->name,
                'size' => isset($result->size) ? (int) $result->size : null,
                'comment' => $result->comment ?? null, // MySQL and PostgreSQL
                'collation' => $result->collation ?? null, // MySQL only
                'engine' => $result->engine ?? null, // MySQL only
            ];
        }, $results);
    }

    /**
     * Process the results of a views query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, schema: string, schema_qualified_name: string, definition: string}>
     */
    public function processViews(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema ?? null,
                'schema_qualified_name' => isset($result->schema) ? $result->schema.'.'.$result->name : $result->name,
                'definition' => $result->definition,
            ];
        }, $results);
    }

    /**
     * Process the results of a types query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, schema: string, schema_qualified_name: string, type: string, category: string, implicit: bool}>
     */
    public function processTypes(array $results): array
    {
        return $results;
    }

    /**
     * Process the results of a columns query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, type: string, type_name: string, collation: string|null, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array{type: string, expression: string|null}|null}>
     */
    public function processColumns(array $results, string $sql = ''): array
    {
        return $results;
    }

    /**
     * Process the results of an indexes query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}>
     */
    public function processIndexes(array $results): array
    {
        return $results;
    }

    /**
     * Process the results of a foreign keys query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string|null, columns: list<string>, foreign_schema: string, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>
     */
    public function processForeignKeys(array $results): array
    {
        return $results;
    }
}
