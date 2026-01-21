<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Processors;

use Hypervel\Database\Query\Builder;

class MySqlProcessor extends Processor
{
    /**
     * Process the results of a column listing query.
     *
     * @deprecated Will be removed in a future Laravel version.
     */
    public function processColumnListing(array $results): array
    {
        return array_map(function ($result) {
            return ((object) $result)->column_name;
        }, $results);
    }

    /**
     * Process an "insert get ID" query.
     */
    #[\Override]
    public function processInsertGetId(Builder $query, string $sql, array $values, ?string $sequence = null): int|string
    {
        $query->getConnection()->insert($sql, $values, $sequence);

        $id = $query->getConnection()->getLastInsertId();

        return is_numeric($id) ? (int) $id : $id;
    }

    #[\Override]
    public function processColumns(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'type_name' => $result->type_name,
                'type' => $result->type,
                'collation' => $result->collation,
                'nullable' => $result->nullable === 'YES',
                'default' => $result->default,
                'auto_increment' => $result->extra === 'auto_increment',
                'comment' => $result->comment ?: null,
                'generation' => $result->expression ? [
                    'type' => match ($result->extra) {
                        'STORED GENERATED' => 'stored',
                        'VIRTUAL GENERATED' => 'virtual',
                        default => null,
                    },
                    'expression' => $result->expression,
                ] : null,
            ];
        }, $results);
    }

    #[\Override]
    public function processIndexes(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $name = strtolower($result->name),
                'columns' => $result->columns ? explode(',', $result->columns) : [],
                'type' => strtolower($result->type),
                'unique' => (bool) $result->unique,
                'primary' => $name === 'primary',
            ];
        }, $results);
    }

    #[\Override]
    public function processForeignKeys(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => $result->foreign_schema,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => strtolower($result->on_update),
                'on_delete' => strtolower($result->on_delete),
            ];
        }, $results);
    }
}
