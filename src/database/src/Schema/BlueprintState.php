<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use Hypervel\Support\Str;

class BlueprintState
{
    /**
     * The blueprint instance.
     */
    protected Blueprint $blueprint;

    /**
     * The connection instance.
     */
    protected Connection $connection;

    /**
     * The columns.
     *
     * @var ColumnDefinition[]
     */
    private array $columns;

    /**
     * The stored table definition.
     */
    private string $tableSql;

    /**
     * The primary key.
     */
    private ?IndexDefinition $primaryKey;

    /**
     * The indexes.
     *
     * @var IndexDefinition[]
     */
    private array $indexes;

    /**
     * The foreign keys.
     *
     * @var ForeignKeyDefinition[]
     */
    private array $foreignKeys;

    /**
     * Create a new blueprint state instance.
     */
    public function __construct(Blueprint $blueprint, Connection $connection)
    {
        $this->blueprint = $blueprint;
        $this->connection = $connection;

        /** @var SQLiteBuilder $schema */
        $schema = $connection->getSchemaBuilder();
        $table = $blueprint->getTable();
        $columnState = $schema->getColumnsForSchemaState($table);
        $this->tableSql = $columnState['sql'];
        /** @var SQLiteGrammar $grammar */
        $grammar = $blueprint->getGrammar();
        $declaresAutoIncrement = $grammar->declaresAutoIncrement($this->tableSql);
        [, $tableName] = $schema->parseSchemaAndTable($table);
        $physicalName = $connection->getTablePrefix() . $tableName;

        $this->columns = (new Collection($columnState['columns']))->map(fn ($column) => new ColumnDefinition([
            'name' => $column['name'],
            'type' => $column['type_name'],
            'full_type_definition' => $column['type'],
            'nullable' => $column['nullable'],
            'default' => is_null($column['default']) ? null : new Expression(Str::wrap($column['default'], '(', ')')),
            'autoIncrement' => $column['auto_increment'] && $declaresAutoIncrement,
            'collation' => $column['collation'],
            'comment' => $column['comment'],
            'virtualAs' => ! is_null($column['generation']) && $column['generation']['type'] === 'virtual'
                ? $column['generation']['expression']
                : null,
            'storedAs' => ! is_null($column['generation']) && $column['generation']['type'] === 'stored'
                ? $column['generation']['expression']
                : null,
        ]))->all();

        $columnCollations = [];

        foreach ($this->columns as $column) {
            $columnCollations[$column->name] = $column->collation ?? 'BINARY';
        }

        [$primary, $indexes] = (new Collection($schema->getIndexesForSchemaState($table)))->map(
            fn ($index) => new IndexDefinition([
                'name' => match (true) {
                    $index['primary'] => 'primary',
                    $index['unique'] => 'unique',
                    default => 'index',
                },
                'index' => $index['physical_name'],
                'columns' => $index['columns'],
                'existing' => true,
                'origin' => $index['origin'],
                'storedSql' => $index['sql'],
                'reconstructible' => $index['reconstructible'],
                'collations' => $index['collations'],
                'columnCollations' => array_map(
                    static fn (string $column): ?string => $columnCollations[$column] ?? null,
                    $index['columns'],
                ),
                'descending' => $index['descending'],
                'renamed' => false,
                'columnRenamed' => false,
                'columnDropped' => false,
            ])
        )->partition(fn ($index) => $index->name === 'primary');

        $this->indexes = $indexes->all();
        $this->primaryKey = $primary->first();

        // Introspection returns already-prefixed physical table names. Quote them here and
        // keep them as expressions so the grammar neither reapplies the prefix nor emits
        // the identifier unquoted.
        $this->foreignKeys = (new Collection($schema->getForeignKeysForSchemaState($table)))->map(fn ($foreignKey) => new ForeignKeyDefinition([
            'columns' => $foreignKey['columns'],
            'on' => new Expression(
                $grammar->wrapIdentifier($foreignKey['foreign_table']),
            ),
            'references' => $foreignKey['implicit'] ? [] : $foreignKey['foreign_columns'],
            'selfReferencing' => strcasecmp($foreignKey['foreign_table'], $physicalName) === 0,
            'onUpdate' => $foreignKey['on_update'],
            'onDelete' => $foreignKey['on_delete'],
        ]))->all();
    }

    /**
     * Get the primary key.
     */
    public function getPrimaryKey(): ?IndexDefinition
    {
        return $this->primaryKey;
    }

    /**
     * Get the columns.
     *
     * @return ColumnDefinition[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get the stored table definition.
     */
    public function getTableSql(): string
    {
        return $this->tableSql;
    }

    /**
     * Get the indexes.
     *
     * @return IndexDefinition[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get the foreign keys.
     *
     * @return ForeignKeyDefinition[]
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Update the blueprint's state.
     */
    public function update(Fluent $command): void
    {
        switch ($command->name) {
            case 'alter':
                // Already handled...
                break;
            case 'add':
                $this->columns[] = $command->column;
                break;
            case 'change':
                foreach ($this->columns as &$column) {
                    if (strcasecmp($column->name, $command->column->name) === 0) {
                        // A type-only change must not respell a column matched case-insensitively.
                        $command->column->name = $column->name;
                        $column = $command->column;
                        break;
                    }
                }

                break;
            case 'renameColumn':
                foreach ($this->columns as $column) {
                    if (strcasecmp($column->name, $command->from) === 0) {
                        $column->name = $command->to;
                        break;
                    }
                }

                if ($this->primaryKey) {
                    $this->primaryKey->columns = $this->replaceColumn(
                        $this->primaryKey->columns,
                        $command->from,
                        $command->to,
                    );
                }

                foreach ($this->indexes as $index) {
                    $index->columnRenamed = true;
                    $index->columns = $this->replaceColumn($index->columns, $command->from, $command->to);

                    if (is_string($index->whereNotNull) && strcasecmp($index->whereNotNull, $command->from) === 0) {
                        $index->whereNotNull = $command->to;
                    }
                }

                foreach ($this->foreignKeys as $foreignKey) {
                    $foreignKey->columns = $this->replaceColumn(
                        $foreignKey->columns,
                        $command->from,
                        $command->to,
                    );
                    if ($foreignKey->selfReferencing) {
                        $foreignKey->references = $this->replaceColumn(
                            (array) $foreignKey->references,
                            $command->from,
                            $command->to,
                        );
                    }
                }

                break;
            case 'dropColumn':
                foreach ($this->indexes as $index) {
                    $index->columnDropped = true;
                }

                $dropped = array_fill_keys(array_map('strtolower', $command->columns), true);
                $this->columns = array_values(array_filter(
                    $this->columns,
                    fn ($column) => ! isset($dropped[strtolower($column->name)]),
                ));

                break;
            case 'primary':
                /** @var IndexDefinition $command */
                $this->primaryKey = $command;
                break;
            case 'unique':
            case 'index':
                $command->existing = false;
                $command->origin = null;
                $command->storedSql = null;
                $command->reconstructible = array_all(
                    $command->columns,
                    static fn (mixed $column): bool => is_string($column),
                );
                $command->collations = null;
                $command->columnCollations = null;
                $command->descending = null;
                $command->renamed = false;
                $command->columnRenamed = false;
                $command->columnDropped = false;

                /** @var IndexDefinition $command */
                $this->indexes[] = $command;
                break;
            case 'renameIndex':
                foreach ($this->indexes as $index) {
                    if (strcasecmp($index->index, $command->from) === 0) {
                        $index->index = $command->to;
                        $index->renamed = true;
                        break;
                    }
                }

                break;
            case 'foreign':
                [, $tableName] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($this->blueprint->getTable());
                /** @var ForeignKeyDefinition $command */
                $command->selfReferencing = is_string($command->on) && strcasecmp($command->on, $tableName) === 0;
                $this->foreignKeys[] = $command;
                break;
            case 'dropPrimary':
                $this->primaryKey = null;
                break;
            case 'dropIndex':
            case 'dropUnique':
                $this->indexes = array_values(
                    array_filter($this->indexes, fn ($index) => strcasecmp($index->index, $command->index) !== 0)
                );

                break;
            case 'dropForeign':
                $columns = array_map('strtolower', $command->columns);
                $this->foreignKeys = array_values(
                    array_filter($this->foreignKeys, fn ($fk) => array_map('strtolower', $fk->columns) !== $columns)
                );

                break;
        }
    }

    /**
     * Replace an exact column name using SQLite's ASCII case-insensitive matching.
     *
     * @param list<ExpressionContract|string> $columns
     * @return list<ExpressionContract|string>
     */
    protected function replaceColumn(array $columns, string $from, string $to): array
    {
        return array_map(
            static fn (ExpressionContract|string $column): ExpressionContract|string => is_string($column) && strcasecmp($column, $from) === 0 ? $to : $column,
            $columns,
        );
    }
}
