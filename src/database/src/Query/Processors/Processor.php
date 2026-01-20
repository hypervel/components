<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Processors;

use Hyperf\Database\Query\Builder;

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
     * @param list<array<string, mixed>> $results
     * @return list<array{name: string, path: string|null, default: bool}>
     */
    public function processSchemas(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'path' => $result->path ?? null,
                'default' => (bool) $result->default,
            ];
        }, $results);
    }

    /**
     * Process the results of a tables query.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function processTables(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema ?? null,
                'schema_qualified_name' => isset($result->schema) ? $result->schema . '.' . $result->name : $result->name,
                'size' => isset($result->size) ? (int) $result->size : null,
                'comment' => $result->comment ?? null,
                'collation' => $result->collation ?? null,
                'engine' => $result->engine ?? null,
            ];
        }, $results);
    }

    /**
     * Process the results of a views query.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, definition: string}>
     */
    public function processViews(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema ?? null,
                'schema_qualified_name' => isset($result->schema) ? $result->schema . '.' . $result->name : $result->name,
                'definition' => $result->definition,
            ];
        }, $results);
    }

    /**
     * Process the results of a types query.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public function processTypes(array $results): array
    {
        return $results;
    }

    /**
     * Process the results of a columns query.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public function processColumns(array $results): array
    {
        return $results;
    }

    /**
     * Process the results of an indexes query.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public function processIndexes(array $results): array
    {
        return $results;
    }

    /**
     * Process the results of a foreign keys query.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public function processForeignKeys(array $results): array
    {
        return $results;
    }
}
