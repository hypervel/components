<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Database\Connection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class Builder
{
    use Macroable;

    /**
     * The database connection instance.
     */
    protected Connection $connection;

    /**
     * The schema grammar instance.
     */
    protected Grammars\Grammar $grammar;

    /**
     * The Blueprint resolver callback.
     *
     * @var \Closure(\Hypervel\Database\Connection, string, \Closure|null): \Hypervel\Database\Schema\Blueprint
     */
    protected ?Closure $resolver = null;

    /**
     * The default string length for migrations.
     */
    public static ?int $defaultStringLength = 255;

    /**
     * The default time precision for migrations.
     */
    public static ?int $defaultTimePrecision = 0;

    /**
     * The default relationship morph key type.
     */
    public static string $defaultMorphKeyType = 'int';

    /**
     * Create a new database Schema manager.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getSchemaGrammar();
    }

    /**
     * Set the default string length for migrations.
     */
    public static function defaultStringLength(int $length): void
    {
        static::$defaultStringLength = $length;
    }

    /**
     * Set the default time precision for migrations.
     */
    public static function defaultTimePrecision(?int $precision): void
    {
        static::$defaultTimePrecision = $precision;
    }

    /**
     * Set the default morph key type for migrations.
     *
     * @throws \InvalidArgumentException
     */
    public static function defaultMorphKeyType(string $type): void
    {
        if (! in_array($type, ['int', 'uuid', 'ulid'])) {
            throw new InvalidArgumentException("Morph key type must be 'int', 'uuid', or 'ulid'.");
        }

        static::$defaultMorphKeyType = $type;
    }

    /**
     * Set the default morph key type for migrations to UUIDs.
     */
    public static function morphUsingUuids(): void
    {
        static::defaultMorphKeyType('uuid');
    }

    /**
     * Set the default morph key type for migrations to ULIDs.
     */
    public static function morphUsingUlids(): void
    {
        static::defaultMorphKeyType('ulid');
    }

    /**
     * Create a database in the schema.
     */
    public function createDatabase(string $name): bool
    {
        return $this->connection->statement(
            $this->grammar->compileCreateDatabase($name)
        );
    }

    /**
     * Drop a database from the schema if the database exists.
     */
    public function dropDatabaseIfExists(string $name): bool
    {
        return $this->connection->statement(
            $this->grammar->compileDropDatabaseIfExists($name)
        );
    }

    /**
     * Get the schemas that belong to the connection.
     *
     * @return list<array{name: string, path: string|null, default: bool}>
     */
    public function getSchemas(): array
    {
        return $this->connection->getPostProcessor()->processSchemas(
            $this->connection->selectFromWriteConnection($this->grammar->compileSchemas())
        );
    }

    /**
     * Determine if the given table exists.
     */
    public function hasTable(string $table): bool
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        if ($sql = $this->grammar->compileTableExists($schema, $table)) {
            return (bool) $this->connection->scalar($sql);
        }

        foreach ($this->getTables($schema ?? $this->getCurrentSchemaName()) as $value) {
            if (strtolower($table) === strtolower($value['name'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given view exists.
     */
    public function hasView(string $view): bool
    {
        [$schema, $view] = $this->parseSchemaAndTable($view);

        $view = $this->connection->getTablePrefix().$view;

        foreach ($this->getViews($schema ?? $this->getCurrentSchemaName()) as $value) {
            if (strtolower($view) === strtolower($value['name'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the tables that belong to the connection.
     *
     * @param  string|string[]|null  $schema
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function getTables(array|string|null $schema = null): array
    {
        return $this->connection->getPostProcessor()->processTables(
            $this->connection->selectFromWriteConnection($this->grammar->compileTables($schema))
        );
    }

    /**
     * Get the names of the tables that belong to the connection.
     *
     * @return list<string>
     */
    public function getTableListing(array|string|null $schema = null, bool $schemaQualified = true): array
    {
        return array_column(
            $this->getTables($schema),
            $schemaQualified ? 'schema_qualified_name' : 'name'
        );
    }

    /**
     * Get the views that belong to the connection.
     *
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, definition: string}>
     */
    public function getViews(array|string|null $schema = null): array
    {
        return $this->connection->getPostProcessor()->processViews(
            $this->connection->selectFromWriteConnection($this->grammar->compileViews($schema))
        );
    }

    /**
     * Get the user-defined types that belong to the connection.
     *
     * @return list<array{name: string, schema: string, schema_qualified_name: string, type: string, category: string, implicit: bool}>
     */
    public function getTypes(array|string|null $schema = null): array
    {
        return $this->connection->getPostProcessor()->processTypes(
            $this->connection->selectFromWriteConnection($this->grammar->compileTypes($schema))
        );
    }

    /**
     * Determine if the given table has a given column.
     */
    public function hasColumn(string $table, string $column): bool
    {
        return in_array(
            strtolower($column), array_map(strtolower(...), $this->getColumnListing($table))
        );
    }

    /**
     * Determine if the given table has given columns.
     *
     * @param  array<string>  $columns
     */
    public function hasColumns(string $table, array $columns): bool
    {
        $tableColumns = array_map(strtolower(...), $this->getColumnListing($table));

        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $tableColumns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a table builder callback if the given table has a given column.
     */
    public function whenTableHasColumn(string $table, string $column, Closure $callback): void
    {
        if ($this->hasColumn($table, $column)) {
            $this->table($table, fn (Blueprint $table) => $callback($table));
        }
    }

    /**
     * Execute a table builder callback if the given table doesn't have a given column.
     */
    public function whenTableDoesntHaveColumn(string $table, string $column, Closure $callback): void
    {
        if (! $this->hasColumn($table, $column)) {
            $this->table($table, fn (Blueprint $table) => $callback($table));
        }
    }

    /**
     * Execute a table builder callback if the given table has a given index.
     */
    public function whenTableHasIndex(string $table, array|string $index, Closure $callback, ?string $type = null): void
    {
        if ($this->hasIndex($table, $index, $type)) {
            $this->table($table, fn (Blueprint $table) => $callback($table));
        }
    }

    /**
     * Execute a table builder callback if the given table doesn't have a given index.
     */
    public function whenTableDoesntHaveIndex(string $table, array|string $index, Closure $callback, ?string $type = null): void
    {
        if (! $this->hasIndex($table, $index, $type)) {
            $this->table($table, fn (Blueprint $table) => $callback($table));
        }
    }

    /**
     * Get the data type for the given column name.
     */
    public function getColumnType(string $table, string $column, bool $fullDefinition = false): string
    {
        $columns = $this->getColumns($table);

        foreach ($columns as $value) {
            if (strtolower($value['name']) === strtolower($column)) {
                return $fullDefinition ? $value['type'] : $value['type_name'];
            }
        }

        throw new InvalidArgumentException("There is no column with name '$column' on table '$table'.");
    }

    /**
     * Get the column listing for a given table.
     *
     * @return list<string>
     */
    public function getColumnListing(string $table): array
    {
        return array_column($this->getColumns($table), 'name');
    }

    /**
     * Get the columns for a given table.
     *
     * @return list<array{name: string, type: string, type_name: string, collation: string|null, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array{type: string, expression: string|null}|null}>
     */
    public function getColumns(string $table): array
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        return $this->connection->getPostProcessor()->processColumns(
            $this->connection->selectFromWriteConnection(
                $this->grammar->compileColumns($schema, $table)
            )
        );
    }

    /**
     * Get the indexes for a given table.
     *
     * @return list<array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}>
     */
    public function getIndexes(string $table): array
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        return $this->connection->getPostProcessor()->processIndexes(
            $this->connection->selectFromWriteConnection(
                $this->grammar->compileIndexes($schema, $table)
            )
        );
    }

    /**
     * Get the names of the indexes for a given table.
     *
     * @return list<string>
     */
    public function getIndexListing(string $table): array
    {
        return array_column($this->getIndexes($table), 'name');
    }

    /**
     * Determine if the given table has a given index.
     */
    public function hasIndex(string $table, array|string $index, ?string $type = null): bool
    {
        $type = is_null($type) ? $type : strtolower($type);

        foreach ($this->getIndexes($table) as $value) {
            $typeMatches = is_null($type)
                || ($type === 'primary' && $value['primary'])
                || ($type === 'unique' && $value['unique'])
                || $type === $value['type'];

            if (($value['name'] === $index || $value['columns'] === $index) && $typeMatches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the foreign keys for a given table.
     */
    public function getForeignKeys(string $table): array
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        return $this->connection->getPostProcessor()->processForeignKeys(
            $this->connection->selectFromWriteConnection(
                $this->grammar->compileForeignKeys($schema, $table)
            )
        );
    }

    /**
     * Modify a table on the schema.
     */
    public function table(string $table, Closure $callback): void
    {
        $this->build($this->createBlueprint($table, $callback));
    }

    /**
     * Create a new table on the schema.
     */
    public function create(string $table, Closure $callback): void
    {
        $this->build(tap($this->createBlueprint($table), function ($blueprint) use ($callback) {
            $blueprint->create();

            $callback($blueprint);
        }));
    }

    /**
     * Drop a table from the schema.
     */
    public function drop(string $table): void
    {
        $this->build(tap($this->createBlueprint($table), function ($blueprint) {
            $blueprint->drop();
        }));
    }

    /**
     * Drop a table from the schema if it exists.
     */
    public function dropIfExists(string $table): void
    {
        $this->build(tap($this->createBlueprint($table), function ($blueprint) {
            $blueprint->dropIfExists();
        }));
    }

    /**
     * Drop columns from a table schema.
     *
     * @param  string|array<string>  $columns
     */
    public function dropColumns(string $table, array|string $columns): void
    {
        $this->table($table, function (Blueprint $blueprint) use ($columns) {
            $blueprint->dropColumn($columns);
        });
    }

    /**
     * Drop all tables from the database.
     *
     * @throws \LogicException
     */
    public function dropAllTables(): void
    {
        throw new LogicException('This database driver does not support dropping all tables.');
    }

    /**
     * Drop all views from the database.
     *
     * @throws \LogicException
     */
    public function dropAllViews(): void
    {
        throw new LogicException('This database driver does not support dropping all views.');
    }

    /**
     * Drop all types from the database.
     *
     * @throws \LogicException
     */
    public function dropAllTypes(): void
    {
        throw new LogicException('This database driver does not support dropping all types.');
    }

    /**
     * Rename a table on the schema.
     */
    public function rename(string $from, string $to): void
    {
        $this->build(tap($this->createBlueprint($from), function ($blueprint) use ($to) {
            $blueprint->rename($to);
        }));
    }

    /**
     * Enable foreign key constraints.
     */
    public function enableForeignKeyConstraints(): bool
    {
        return $this->connection->statement(
            $this->grammar->compileEnableForeignKeyConstraints()
        );
    }

    /**
     * Disable foreign key constraints.
     */
    public function disableForeignKeyConstraints(): bool
    {
        return $this->connection->statement(
            $this->grammar->compileDisableForeignKeyConstraints()
        );
    }

    /**
     * Disable foreign key constraints during the execution of a callback.
     */
    public function withoutForeignKeyConstraints(Closure $callback): mixed
    {
        $this->disableForeignKeyConstraints();

        try {
            return $callback();
        } finally {
            $this->enableForeignKeyConstraints();
        }
    }

    /**
     * Create the vector extension on the schema if it does not exist.
     */
    public function ensureVectorExtensionExists(?string $schema = null): void
    {
        $this->ensureExtensionExists('vector', $schema);
    }

    /**
     * Create a new extension on the schema if it does not exist.
     */
    public function ensureExtensionExists(string $name, ?string $schema = null): void
    {
        if (! $this->getConnection() instanceof PostgresConnection) {
            throw new RuntimeException('Extensions are only supported by Postgres.');
        }

        $name = $this->getConnection()->getSchemaGrammar()->wrap($name);

        $this->getConnection()->statement(match (filled($schema)) {
            true => "create extension if not exists {$name} schema {$this->getConnection()->getSchemaGrammar()->wrap($schema)}",
            false => "create extension if not exists {$name}",
        });
    }

    /**
     * Execute the blueprint to build / modify the table.
     */
    protected function build(Blueprint $blueprint): void
    {
        $blueprint->build();
    }

    /**
     * Create a new command set with a Closure.
     */
    protected function createBlueprint(string $table, ?Closure $callback = null): Blueprint
    {
        $connection = $this->connection;

        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $connection, $table, $callback);
        }

        return Container::getInstance()->make(Blueprint::class, compact('connection', 'table', 'callback'));
    }

    /**
     * Get the names of the current schemas for the connection.
     *
     * @return string[]|null
     */
    public function getCurrentSchemaListing(): ?array
    {
        return null;
    }

    /**
     * Get the default schema name for the connection.
     */
    public function getCurrentSchemaName(): ?string
    {
        return $this->getCurrentSchemaListing()[0] ?? null;
    }

    /**
     * Parse the given database object reference and extract the schema and table.
     */
    public function parseSchemaAndTable(string $reference, bool|string|null $withDefaultSchema = null): array
    {
        $segments = explode('.', $reference);

        if (count($segments) > 2) {
            throw new InvalidArgumentException(
                "Using three-part references is not supported, you may use `Schema::connection('{$segments[0]}')` instead."
            );
        }

        $table = $segments[1] ?? $segments[0];

        $schema = match (true) {
            isset($segments[1]) => $segments[0],
            is_string($withDefaultSchema) => $withDefaultSchema,
            $withDefaultSchema => $this->getCurrentSchemaName(),
            default => null,
        };

        return [$schema, $table];
    }

    /**
     * Get the database connection instance.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Set the Schema Blueprint resolver callback.
     *
     * @param  \Closure(\Hypervel\Database\Connection, string, \Closure|null): \Hypervel\Database\Schema\Blueprint  $resolver
     */
    public function blueprintResolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }
}
