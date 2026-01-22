<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema\Grammars;

use Hypervel\Database\Concerns\CompilesJsonPaths;
use Hypervel\Database\Contracts\Query\Expression;
use Hypervel\Database\Grammar as BaseGrammar;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Arr;
use Hypervel\Support\Fluent;
use RuntimeException;
use UnitEnum;

use function Hypervel\Support\enum_value;

abstract class Grammar extends BaseGrammar
{
    use CompilesJsonPaths;

    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected array $modifiers = [];

    /**
     * If this Grammar supports schema changes wrapped in a transaction.
     */
    protected bool $transactions = false;

    /**
     * The commands to be executed outside of create or alter command.
     *
     * @var string[]
     */
    protected array $fluentCommands = [];

    /**
     * Compile a create database command.
     */
    public function compileCreateDatabase(string $name): string
    {
        return sprintf('create database %s',
            $this->wrapValue($name),
        );
    }

    /**
     * Compile a drop database if exists command.
     */
    public function compileDropDatabaseIfExists(string $name): string
    {
        return sprintf('drop database if exists %s',
            $this->wrapValue($name)
        );
    }

    /**
     * Compile the query to determine the schemas.
     */
    public function compileSchemas(): string
    {
        throw new RuntimeException('This database driver does not support retrieving schemas.');
    }

    /**
     * Compile the query to determine if the given table exists.
     */
    public function compileTableExists(?string $schema, string $table): ?string
    {
        return null;
    }

    /**
     * Compile the query to determine the tables.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileTables(string|array|null $schema): string
    {
        throw new RuntimeException('This database driver does not support retrieving tables.');
    }

    /**
     * Compile the query to determine the views.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileViews(string|array|null $schema): string
    {
        throw new RuntimeException('This database driver does not support retrieving views.');
    }

    /**
     * Compile the query to determine the user-defined types.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileTypes(string|array|null $schema): string
    {
        throw new RuntimeException('This database driver does not support retrieving user-defined types.');
    }

    /**
     * Compile the query to determine the columns.
     */
    public function compileColumns(?string $schema, string $table): string
    {
        throw new RuntimeException('This database driver does not support retrieving columns.');
    }

    /**
     * Compile the query to determine the indexes.
     */
    public function compileIndexes(?string $schema, string $table): string
    {
        throw new RuntimeException('This database driver does not support retrieving indexes.');
    }

    /**
     * Compile a vector index key command.
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        throw new RuntimeException('The database driver in use does not support vector indexes.');
    }

    /**
     * Compile the query to determine the foreign keys.
     */
    public function compileForeignKeys(?string $schema, string $table): string
    {
        throw new RuntimeException('This database driver does not support retrieving foreign keys.');
    }

    /**
     * Compile the command to enable foreign key constraints.
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        throw new RuntimeException('This database driver does not support enabling foreign key constraints.');
    }

    /**
     * Compile the command to disable foreign key constraints.
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        throw new RuntimeException('This database driver does not support disabling foreign key constraints.');
    }

    /**
     * Compile a rename column command.
     *
     * @return list<string>|string
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): array|string
    {
        return sprintf('alter table %s rename column %s to %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to)
        );
    }

    /**
     * Compile a change column command into a series of SQL statements.
     *
     * @return list<string>|string
     */
    public function compileChange(Blueprint $blueprint, Fluent $command): array|string
    {
        throw new RuntimeException('This database driver does not support modifying columns.');
    }

    /**
     * Compile a fulltext index key command.
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command): string
    {
        throw new RuntimeException('This database driver does not support fulltext index creation.');
    }

    /**
     * Compile a drop fulltext index command.
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command): string
    {
        throw new RuntimeException('This database driver does not support fulltext index removal.');
    }

    /**
     * Compile a foreign key command.
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): ?string
    {
        // We need to prepare several of the elements of the foreign key definition
        // before we can create the SQL, such as wrapping the tables and convert
        // an array of columns to comma-delimited strings for the SQL queries.
        $sql = sprintf('alter table %s add constraint %s ',
            $this->wrapTable($blueprint),
            $this->wrap($command->index)
        );

        // Once we have the initial portion of the SQL statement we will add on the
        // key name, table name, and referenced columns. These will complete the
        // main portion of the SQL statement and this SQL will almost be done.
        $sql .= sprintf('foreign key (%s) references %s (%s)',
            $this->columnize($command->columns),
            $this->wrapTable($command->on),
            $this->columnize((array) $command->references)
        );

        // Once we have the basic foreign key creation statement constructed we can
        // build out the syntax for what should happen on an update or delete of
        // the affected columns, which will get something like "cascade", etc.
        if (! is_null($command->onDelete)) {
            $sql .= " on delete {$command->onDelete}";
        }

        if (! is_null($command->onUpdate)) {
            $sql .= " on update {$command->onUpdate}";
        }

        return $sql;
    }

    /**
     * Compile a drop foreign key command.
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): array|string|null
    {
        throw new RuntimeException('This database driver does not support dropping foreign keys.');
    }

    /**
     * Compile the blueprint's added column definitions.
     *
     * @return string[]
     */
    protected function getColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            $columns[] = $this->getColumn($blueprint, $column);
        }

        return $columns;
    }

    /**
     * Compile the column definition.
     *
     * @param  \Hypervel\Database\Schema\ColumnDefinition  $column
     */
    protected function getColumn(Blueprint $blueprint, Fluent $column): string
    {
        // Each of the column types has their own compiler functions, which are tasked
        // with turning the column definition into its SQL format for this platform
        // used by the connection. The column's modifiers are compiled and added.
        $sql = $this->wrap($column).' '.$this->getType($column);

        return $this->addModifiers($sql, $blueprint, $column);
    }

    /**
     * Get the SQL for the column data type.
     */
    protected function getType(Fluent $column): string
    {
        return $this->{'type'.ucfirst($column->type)}($column);
    }

    /**
     * Create the column definition for a generated, computed column type.
     */
    protected function typeComputed(Fluent $column): void
    {
        throw new RuntimeException('This database driver does not support the computed type.');
    }

    /**
     * Create the column definition for a vector type.
     */
    protected function typeVector(Fluent $column): string
    {
        throw new RuntimeException('This database driver does not support the vector type.');
    }

    /**
     * Create the column definition for a raw column type.
     */
    protected function typeRaw(Fluent $column): string
    {
        return $column->offsetGet('definition');
    }

    /**
     * Add the column modifiers to the definition.
     */
    protected function addModifiers(string $sql, Blueprint $blueprint, Fluent $column): string
    {
        foreach ($this->modifiers as $modifier) {
            if (method_exists($this, $method = "modify{$modifier}")) {
                $sql .= $this->{$method}($blueprint, $column);
            }
        }

        return $sql;
    }

    /**
     * Get the command with a given name if it exists on the blueprint.
     */
    protected function getCommandByName(Blueprint $blueprint, string $name): ?Fluent
    {
        $commands = $this->getCommandsByName($blueprint, $name);

        if (count($commands) > 0) {
            return Arr::first($commands);
        }

        return null;
    }

    /**
     * Get all of the commands with a given name.
     *
     * @return Fluent[]
     */
    protected function getCommandsByName(Blueprint $blueprint, string $name): array
    {
        return array_filter($blueprint->getCommands(), function ($value) use ($name) {
            return $value->name == $name;
        });
    }

    /**
     * Determine if a command with a given name exists on the blueprint.
     */
    protected function hasCommand(Blueprint $blueprint, string $name): bool
    {
        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a prefix to an array of values.
     *
     * @param  string[]  $values
     * @return string[]
     */
    public function prefixArray(string $prefix, array $values): array
    {
        return array_map(function ($value) use ($prefix) {
            return $prefix.' '.$value;
        }, $values);
    }

    /**
     * Wrap a table in keyword identifiers.
     */
    public function wrapTable(Blueprint|Expression|string $table, ?string $prefix = null): string
    {
        return parent::wrapTable(
            $table instanceof Blueprint ? $table->getTable() : $table,
            $prefix
        );
    }

    /**
     * Wrap a value in keyword identifiers.
     */
    public function wrap(Fluent|Expression|string $value): string
    {
        return parent::wrap(
            $value instanceof Fluent ? $value->name : $value,
        );
    }

    /**
     * Format a value so that it can be used in "default" clauses.
     */
    protected function getDefaultValue(mixed $value): string|int|float
    {
        if ($value instanceof Expression) {
            return $this->getValue($value);
        }

        if ($value instanceof UnitEnum) {
            return "'".str_replace("'", "''", enum_value($value))."'";
        }

        return is_bool($value)
            ? "'".(int) $value."'"
            : "'".str_replace("'", "''", (string) $value)."'";
    }

    /**
     * Get the fluent commands for the grammar.
     *
     * @return string[]
     */
    public function getFluentCommands(): array
    {
        return $this->fluentCommands;
    }

    /**
     * Check if this Grammar supports schema changes wrapped in a transaction.
     */
    public function supportsSchemaTransactions(): bool
    {
        return $this->transactions;
    }
}
