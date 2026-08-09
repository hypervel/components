<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Query\Processors\SQLiteProcessor;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\File;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Throwable;

/**
 * @property \Hypervel\Database\Schema\Grammars\SQLiteGrammar $grammar
 */
class SQLiteBuilder extends Builder
{
    /**
     * Execute the given schema blueprint.
     */
    #[Override]
    public function executeBlueprint(Blueprint $blueprint): void
    {
        $statements = $blueprint->toSql();

        // SQLite cannot wrap a whole migration because foreign-key pragmas must run
        // outside transactions. This narrower boundary audits one known Blueprint.
        if (count($statements) < 2
            || $this->connection->pretending()
            || ! $this->commandsAreDeclaredOn($blueprint, SQLiteGrammar::class)
        ) {
            $this->executeStatements($statements);

            return;
        }

        [$statements, $rebuildRequiresForeignKeySuppression] = $this->withoutForeignKeyGuardStatements($statements);

        if ($this->connection->transactionLevel() > 0) {
            if ($rebuildRequiresForeignKeySuppression && $this->tableHasRows($blueprint)) {
                throw new RuntimeException(
                    "SQLite cannot rebuild the populated table [{$blueprint->getTable()}] while foreign key constraints are enabled within an active transaction."
                );
            }

            $this->connection->transaction(
                fn () => $this->executeStatements($statements)
            );

            return;
        }

        if (! $rebuildRequiresForeignKeySuppression) {
            $this->connection->transaction(
                fn () => $this->executeStatements($statements)
            );

            return;
        }

        $this->executeSessionStatement($this->grammar->compileDisableForeignKeyConstraints());

        try {
            $this->connection->transaction(
                fn () => $this->executeStatements($statements)
            );
        } finally {
            $this->executeSessionStatement($this->grammar->compileEnableForeignKeyConstraints());
        }
    }

    /**
     * Create a database in the schema.
     */
    #[Override]
    public function createDatabase(string $name): bool
    {
        $this->validateDatabasePath($name);

        return File::put($name, '') !== false;
    }

    /**
     * Drop a database from the schema if the database exists.
     */
    #[Override]
    public function dropDatabaseIfExists(string $name): bool
    {
        $this->validateDatabasePath($name);

        return ! File::exists($name) || File::delete($name);
    }

    /**
     * Validate the database name for filesystem management.
     */
    protected function validateDatabasePath(string $name): void
    {
        if (SQLiteDatabase::isInMemory($name) || SQLiteDatabase::isUri($name)) {
            throw new InvalidArgumentException(
                "SQLite database management requires a plain filesystem path; [{$name}] is not supported."
            );
        }
    }

    #[Override]
    public function getTables(array|string|null $schema = null): array
    {
        try {
            // Compile options are SQLite library metadata shared by every PDO in the process.
            $withSize = (bool) $this->connection->scalar($this->grammar->compileDbstatExists());
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

    #[Override]
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

    #[Override]
    public function getColumns(string $table): array
    {
        return $this->getColumnsForSchemaState($table)['columns'];
    }

    /**
     * Get the columns and stored table definition used to reconstruct SQLite schema state.
     *
     * @internal
     * @return array{columns: list<array{name: string, type: string, type_name: string, collation: null|string, nullable: bool, default: mixed, auto_increment: bool, comment: null|string, generation: null|array{type: string, expression: null|string}}>, sql: string}
     */
    public function getColumnsForSchemaState(string $table): array
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix() . $table;
        $columns = $this->connection->selectFromWriteConnection($this->grammar->compileColumns($schema, $table));
        // Rebuild guards must inspect the stored definition on the same write PDO as the columns.
        $sql = $this->connection->scalar($this->grammar->compileSqlCreateStatement($schema, $table), [], false) ?? '';

        return [
            'columns' => $this->connection->getPostProcessor()->processColumns(
                $columns,
                $sql,
            ),
            'sql' => $sql,
        ];
    }

    /**
     * Get the indexes used to reconstruct SQLite schema state.
     *
     * @internal
     * @return list<array{name: string, physical_name: string, columns: list<string>, type: null|string, unique: bool, primary: bool, sql: null|string, origin: null|string, reconstructible: bool, collations: null|list<string>, descending: null|list<bool>}>
     */
    public function getIndexesForSchemaState(string $table): array
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix() . $table;

        /** @var SQLiteProcessor $processor */
        $processor = $this->connection->getPostProcessor();

        return $processor->processIndexesForSchemaState(
            $this->connection->selectFromWriteConnection(
                $this->grammar->compileIndexes($schema, $table)
            )
        );
    }

    /**
     * Drop all tables from the database.
     */
    #[Override]
    public function dropAllTables(): void
    {
        $this->ensureNoActiveTransaction('drop all tables');

        foreach ($this->getCurrentSchemaListing() as $schema) {
            $this->dropSchemaObjects($schema, $this->grammar->compileDropAllTables($schema));
        }
    }

    /**
     * Drop all views from the database.
     */
    #[Override]
    public function dropAllViews(): void
    {
        $this->ensureNoActiveTransaction('drop all views');

        foreach ($this->getCurrentSchemaListing() as $schema) {
            $this->dropSchemaObjects($schema, $this->grammar->compileDropAllViews($schema));
        }
    }

    /**
     * Drop schema objects and reload SQLite's schema cache.
     */
    protected function dropSchemaObjects(string $schema, string $statement): void
    {
        $writableSchemaEnabled = (bool) $this->pragma('writable_schema');
        $supportsReset = version_compare($this->connection->getServerVersion(), '3.37.0', '>=');

        try {
            if (! $writableSchemaEnabled) {
                $this->executeSessionStatement($this->grammar->pragma('writable_schema', 1));
            }

            try {
                $this->executeStatements([$statement]);
            } finally {
                $this->executeSessionStatement($this->grammar->pragma(
                    'writable_schema',
                    $supportsReset ? 'RESET' : 0
                ));
            }

            try {
                $this->executeStatements([$this->grammar->compileRebuild($schema)]);
            } catch (Throwable $exception) {
                if (! $supportsReset) {
                    $this->connection->markCurrentSessionStateUnknown();
                }

                throw $exception;
            }
        } finally {
            if ($writableSchemaEnabled) {
                $this->executeSessionStatement($this->grammar->pragma('writable_schema', 1));
            }
        }
    }

    /**
     * Get the value for the given pragma name or set the given value.
     */
    public function pragma(string $key, mixed $value = null): mixed
    {
        // Pragmas may be connection-local state, so getters must inspect the write PDO that setters mutate.
        return is_null($value)
            ? $this->connection->scalar($this->grammar->pragma($key), [], false)
            : $this->connection->statement($this->grammar->pragma($key, $value));
    }

    /**
     * Empty the database file.
     *
     * The caller must ensure that no other connection is using the target database file.
     */
    public function refreshDatabaseFile(?string $path = null): void
    {
        if ($path === null) {
            $this->ensureNoActiveTransaction('refresh the database file');

            $database = $this->connection->getDatabaseName();

            if (SQLiteDatabase::isInMemory($database)) {
                throw new InvalidArgumentException(
                    "SQLite database management requires a plain filesystem path; [{$database}] is not supported."
                );
            }

            if ($this->pragma('journal_mode') === 'wal') {
                throw new RuntimeException(
                    'SQLite database files cannot be refreshed through a connection using WAL journal mode. Use dropAllTables() to empty a database while connections are using it.'
                );
            }

            $path = array_column($this->getSchemas(), 'path', 'name')['main'] ?? null;

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to resolve the SQLite database file path.');
            }
        }

        $this->validateDatabasePath($path);

        if (File::put($path, '') === false) {
            throw new RuntimeException("Unable to refresh SQLite database file [{$path}].");
        }
    }

    /**
     * Ensure a schema operation is not running within a transaction.
     */
    protected function ensureNoActiveTransaction(string $operation): void
    {
        if ($this->connection->transactionLevel() > 0) {
            throw new RuntimeException("SQLite cannot {$operation} within an active transaction.");
        }
    }

    /**
     * Enable foreign key constraints.
     */
    #[Override]
    public function enableForeignKeyConstraints(): bool
    {
        $this->ensureForeignKeyConstraintsCanBeChanged();

        return parent::enableForeignKeyConstraints();
    }

    /**
     * Disable foreign key constraints.
     */
    #[Override]
    public function disableForeignKeyConstraints(): bool
    {
        $this->ensureForeignKeyConstraintsCanBeChanged();

        return parent::disableForeignKeyConstraints();
    }

    /**
     * Determine whether foreign key constraints are enabled.
     */
    #[Override]
    protected function foreignKeyConstraintsAreEnabled(): bool
    {
        return (bool) $this->pragma('foreign_keys');
    }

    /**
     * Set the foreign key constraint state for an internal suppression scope.
     */
    #[Override]
    protected function setForeignKeyConstraints(bool $enabled): void
    {
        $this->ensureForeignKeyConstraintsCanBeChanged();

        parent::setForeignKeyConstraints($enabled);
    }

    /**
     * Ensure foreign key constraints can be changed on this connection.
     */
    protected function ensureForeignKeyConstraintsCanBeChanged(): void
    {
        if ($this->connection->transactionLevel() > 0) {
            throw new RuntimeException(
                'SQLite foreign key constraints cannot be enabled or disabled within an active transaction.'
            );
        }
    }

    /**
     * Remove the rebuild-only foreign-key guard statements.
     *
     * @param list<string> $statements
     * @return array{list<string>, bool}
     */
    protected function withoutForeignKeyGuardStatements(array $statements): array
    {
        $disable = $this->grammar->compileDisableForeignKeyConstraints();
        $enable = $this->grammar->compileEnableForeignKeyConstraints();
        $requiresForeignKeySuppression = false;

        $statements = array_values(array_filter(
            $statements,
            function (string $statement) use ($disable, $enable, &$requiresForeignKeySuppression): bool {
                if ($statement !== $disable && $statement !== $enable) {
                    return true;
                }

                $requiresForeignKeySuppression = true;

                return false;
            }
        ));

        return [$statements, $requiresForeignKeySuppression];
    }

    /**
     * Determine whether the Blueprint's table contains any rows.
     */
    protected function tableHasRows(Blueprint $blueprint): bool
    {
        return (bool) $this->connection->scalar(
            'select exists (select 1 from ' . $this->grammar->wrapTable($blueprint) . ' limit 1)',
            [],
            false,
        );
    }

    /**
     * Get the names of current schemas for the connection.
     */
    #[Override]
    public function getCurrentSchemaListing(): array
    {
        return ['main'];
    }
}
