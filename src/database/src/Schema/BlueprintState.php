<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Expression;
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
     * @var \Hypervel\Database\Schema\ColumnDefinition[]
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
     * @var \Hypervel\Database\Schema\IndexDefinition[]
     */
    private array $indexes;

    /**
     * The foreign keys.
     *
     * @var \Hypervel\Database\Schema\ForeignKeyDefinition[]
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

        $this->columns = (new Collection($columnState['columns']))->map(fn ($column) => new ColumnDefinition([
            'name' => $column['name'],
            'type' => $column['type_name'],
            'full_type_definition' => $column['type'],
            'nullable' => $column['nullable'],
            'default' => is_null($column['default']) ? null : new Expression(Str::wrap($column['default'], '(', ')')),
            'autoIncrement' => $column['auto_increment'],
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
        $this->foreignKeys = (new Collection($schema->getForeignKeys($table)))->map(fn ($foreignKey) => new ForeignKeyDefinition([
            'columns' => $foreignKey['columns'],
            'on' => new Expression(
                $blueprint->getGrammar()->wrapIdentifier($foreignKey['foreign_table']),
            ),
            'references' => $foreignKey['foreign_columns'],
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
     * @return \Hypervel\Database\Schema\ColumnDefinition[]
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
     * @return \Hypervel\Database\Schema\IndexDefinition[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get the foreign keys.
     *
     * @return \Hypervel\Database\Schema\ForeignKeyDefinition[]
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
                    if ($column->name === $command->column->name) {
                        $column = $command->column;
                        break;
                    }
                }

                break;
            case 'renameColumn':
                foreach ($this->columns as $column) {
                    if ($column->name === $command->from) {
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
                }

                foreach ($this->foreignKeys as $foreignKey) {
                    $foreignKey->columns = $this->replaceColumn(
                        $foreignKey->columns,
                        $command->from,
                        $command->to,
                    );
                }

                break;
            case 'dropColumn':
                foreach ($this->indexes as $index) {
                    $index->columnDropped = true;
                }

                $this->columns = array_values(
                    array_filter($this->columns, fn ($column) => ! in_array($column->name, $command->columns, true))
                );

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
                /** @var ForeignKeyDefinition $command */
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
                $this->foreignKeys = array_values(
                    array_filter($this->foreignKeys, fn ($fk) => $fk->columns !== $command->columns)
                );

                break;
        }
    }

    /**
     * Replace an exact column name in a projection.
     *
     * @param list<Expression|string> $columns
     * @return list<Expression|string>
     */
    protected function replaceColumn(array $columns, string $from, string $to): array
    {
        return array_map(
            static fn (Expression|string $column): Expression|string => $column === $from ? $to : $column,
            $columns,
        );
    }
}
