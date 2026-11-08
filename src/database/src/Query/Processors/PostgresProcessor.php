<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Processors;

use Hypervel\Database\Query\Builder;
use Override;

class PostgresProcessor extends Processor
{
    /**
     * Process an "insert get ID" query.
     */
    #[Override]
    public function processInsertGetId(Builder $query, string $sql, array $values, ?string $sequence = null): int|string
    {
        $connection = $query->getConnection();

        $connection->recordsHaveBeenModified();

        $result = $connection->selectFromWriteConnection($sql, $values)[0];

        $sequence = $sequence ?: 'id';

        $id = is_object($result) ? $result->{$sequence} : $result[$sequence];

        return is_numeric($id) ? (int) $id : $id;
    }

    #[Override]
    public function processTypes(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema,
                'schema_qualified_name' => $result->schema . '.' . $result->name,
                'implicit' => (bool) $result->implicit,
                'type' => match (strtolower($result->type)) {
                    'b' => 'base',
                    'c' => 'composite',
                    'd' => 'domain',
                    'e' => 'enum',
                    'p' => 'pseudo',
                    'r' => 'range',
                    'm' => 'multirange',
                    default => null,
                },
                'category' => match (strtolower($result->category)) {
                    'a' => 'array',
                    'b' => 'boolean',
                    'c' => 'composite',
                    'd' => 'date_time',
                    'e' => 'enum',
                    'g' => 'geometric',
                    'i' => 'network_address',
                    'n' => 'numeric',
                    'p' => 'pseudo',
                    'r' => 'range',
                    's' => 'string',
                    't' => 'timespan',
                    'u' => 'user_defined',
                    'v' => 'bit_string',
                    'x' => 'unknown',
                    'z' => 'internal_use',
                    default => null,
                },
            ];
        }, $results);
    }

    #[Override]
    public function processColumns(array $results, string $sql = ''): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            // Identity columns do not have a nextval default in pg_attrdef.
            $autoincrement = ! empty($result->identity)
                || ($result->default !== null && str_starts_with($result->default, 'nextval('));

            return [
                'name' => $result->name,
                'type_name' => $result->type_name,
                'type' => $result->type,
                'collation' => $result->collation,
                'nullable' => (bool) $result->nullable,
                'default' => $result->generated ? null : $result->default,
                'auto_increment' => $autoincrement,
                'comment' => $result->comment,
                'generation' => $result->generated ? [
                    'type' => match ($result->generated) {
                        's' => 'stored',
                        'v' => 'virtual',
                        default => null,
                    },
                    'expression' => $result->default,
                ] : null,
            ];
        }, $results);
    }

    #[Override]
    public function processIndexes(array $results): array
    {
        $indexes = [];

        foreach ($results as $result) {
            $result = (object) $result;

            $indexes[$result->name] ??= [
                'name' => strtolower($result->name),
                'columns' => [],
                'key_columns' => [],
                'type' => strtolower($result->type),
                'unique' => (bool) $result->unique,
                'primary' => (bool) $result->primary,
                'partial' => (bool) $result->partial,
            ];

            $indexes[$result->name]['key_columns'][] = $result->column_name;
        }

        foreach ($indexes as &$index) {
            // A partial column list would falsely identify a functional index as a plain one.
            $index['columns'] = in_array(null, $index['key_columns'], true) ? [] : $index['key_columns'];
        }

        return array_values($indexes);
    }

    #[Override]
    public function processForeignKeys(array $results): array
    {
        $foreignKeys = [];

        foreach ($results as $result) {
            $result = (object) $result;

            $foreignKeys[$result->name] ??= [
                'name' => $result->name,
                'columns' => [],
                'foreign_schema' => $result->foreign_schema,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => [],
                'on_update' => match (strtolower($result->on_update)) {
                    'a' => 'no action',
                    'r' => 'restrict',
                    'c' => 'cascade',
                    'n' => 'set null',
                    'd' => 'set default',
                    default => null,
                },
                'on_delete' => match (strtolower($result->on_delete)) {
                    'a' => 'no action',
                    'r' => 'restrict',
                    'c' => 'cascade',
                    'n' => 'set null',
                    'd' => 'set default',
                    default => null,
                },
            ];
            $foreignKeys[$result->name]['columns'][] = $result->column_name;
            $foreignKeys[$result->name]['foreign_columns'][] = $result->foreign_column_name;
        }

        return array_values($foreignKeys);
    }
}
