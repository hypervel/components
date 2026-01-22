<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\QueryException;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\File;

/**
 * @property \Hypervel\Database\Schema\Grammars\SQLiteGrammar $grammar
 */
class SQLiteBuilder extends Builder
{
    /**
     * Create a database in the schema.
     */
    #[\Override]
    public function createDatabase(string $name): bool
    {
        return File::put($name, '') !== false;
    }

    /**
     * Drop a database from the schema if the database exists.
     */
    #[\Override]
    public function dropDatabaseIfExists(string $name): bool
    {
        return ! File::exists($name) || File::delete($name);
    }

    #[\Override]
    public function getTables(array|string|null $schema = null): array
    {
        try {
            $withSize = $this->connection->scalar($this->grammar->compileDbstatExists());
        } catch (QueryException) {
            $withSize = false;
        }

        if (version_compare($this->connection->getServerVersion(), '3.37.0', '<')) {
            $schema ??= array_column($this->getSchemas(), 'name');

            $tables = [];

            foreach (Arr::wrap($schema) as $name) {
                $tables = array_merge($tables, $this->connection->selectFromWriteConnection(
                    $this->grammar->compileLegacyTables($name, $withSize)
                ));
            }

            return $this->connection->getPostProcessor()->processTables($tables);
        }

        return $this->connection->getPostProcessor()->processTables(
            $this->connection->selectFromWriteConnection(
                $this->grammar->compileTables($schema, $withSize)
            )
        );
    }

    #[\Override]
    public function getViews(array|string|null $schema = null): array
    {
        $schema ??= array_column($this->getSchemas(), 'name');

        $views = [];

        foreach (Arr::wrap($schema) as $name) {
            $views = array_merge($views, $this->connection->selectFromWriteConnection(
                $this->grammar->compileViews($name)
            ));
        }

        return $this->connection->getPostProcessor()->processViews($views);
    }

    #[\Override]
    public function getColumns(string $table): array
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        return $this->connection->getPostProcessor()->processColumns(
            $this->connection->selectFromWriteConnection($this->grammar->compileColumns($schema, $table)),
            $this->connection->scalar($this->grammar->compileSqlCreateStatement($schema, $table))
        );
    }

    /**
     * Drop all tables from the database.
     */
    #[\Override]
    public function dropAllTables(): void
    {
        foreach ($this->getCurrentSchemaListing() as $schema) {
            $database = $schema === 'main'
                ? $this->connection->getDatabaseName()
                : (array_column($this->getSchemas(), 'path', 'name')[$schema] ?: ':memory:');

            if ($database !== ':memory:' &&
                ! str_contains($database, '?mode=memory') &&
                ! str_contains($database, '&mode=memory')
            ) {
                $this->refreshDatabaseFile($database);
            } else {
                $this->pragma('writable_schema', 1);

                $this->connection->statement($this->grammar->compileDropAllTables($schema));

                $this->pragma('writable_schema', 0);

                $this->connection->statement($this->grammar->compileRebuild($schema));
            }
        }
    }

    /**
     * Drop all views from the database.
     */
    #[\Override]
    public function dropAllViews(): void
    {
        foreach ($this->getCurrentSchemaListing() as $schema) {
            $this->pragma('writable_schema', 1);

            $this->connection->statement($this->grammar->compileDropAllViews($schema));

            $this->pragma('writable_schema', 0);

            $this->connection->statement($this->grammar->compileRebuild($schema));
        }
    }

    /**
     * Get the value for the given pragma name or set the given value.
     */
    public function pragma(string $key, mixed $value = null): mixed
    {
        return is_null($value)
            ? $this->connection->scalar($this->grammar->pragma($key))
            : $this->connection->statement($this->grammar->pragma($key, $value));
    }

    /**
     * Empty the database file.
     */
    public function refreshDatabaseFile(?string $path = null): void
    {
        file_put_contents($path ?? $this->connection->getDatabaseName(), '');
    }

    /**
     * Get the names of current schemas for the connection.
     */
    #[\Override]
    public function getCurrentSchemaListing(): array
    {
        return ['main'];
    }
}
