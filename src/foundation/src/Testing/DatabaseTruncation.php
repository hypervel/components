<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Foundation\Testing\Concerns\InteractsWithParallelDatabase;
use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use LogicException;

/**
 * This concern may be combined with RefreshDatabase or DatabaseTransactions;
 * each trait should manage its own connections. It cannot be combined with
 * DatabaseMigrations or LazilyRefreshDatabase.
 */
trait DatabaseTruncation
{
    use CanConfigureMigrationCommands;
    use InteractsWithParallelDatabase;

    /**
     * The cached names of the database tables for each connection.
     */
    protected static array $allTables;

    /**
     * Truncate the database tables for all configured connections.
     */
    protected function truncateDatabaseTables(): void
    {
        $uses = class_uses_recursive(static::class);

        if (isset($uses[LazilyRefreshDatabase::class])) {
            throw new LogicException('DatabaseTruncation cannot be combined with LazilyRefreshDatabase.');
        }

        if (isset($uses[DatabaseMigrations::class])) {
            throw new LogicException('DatabaseTruncation cannot be combined with DatabaseMigrations.');
        }

        if (
            (isset($uses[RefreshDatabase::class]) || isset($uses[DatabaseTransactions::class]))
            && ($this->seeder() || $this->shouldSeed())
        ) {
            throw new LogicException(
                'Automatic database seeding is not supported when DatabaseTruncation is combined with RefreshDatabase or DatabaseTransactions. Seed each connection from the hook for its reset strategy instead.'
            );
        }

        $this->ensureParallelDatabaseExists();
        $this->restoreInMemoryDatabases();

        if (
            isset($uses[RefreshDatabase::class])
            && RefreshDatabaseState::$migrated
            && $this->hasMissingInMemoryDatabaseForTruncation()
        ) {
            // Eager refresh has migrated this application; retain the truncation PDOs before user hooks run.
            $this->cacheInMemoryDatabases();
        }

        $this->beforeTruncatingDatabase();

        // Migrate and seed the database on first run...
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            $this->app->make(Kernel::class)->setArtisan(null);

            $this->cacheInMemoryDatabases();

            RefreshDatabaseState::$migrated = true;

            return;
        }

        // Always clear any test data on subsequent runs...
        $this->truncateTablesForAllConnections();

        if ($seeder = $this->seeder()) {
            // Use a specific seeder class...
            $this->artisan('db:seed', ['--class' => $seeder]);
        } elseif ($this->shouldSeed()) {
            // Use the default seeder class...
            $this->artisan('db:seed');
        }

        $this->afterTruncatingDatabase();
    }

    /**
     * Restore the in-memory databases between tests.
     */
    protected function restoreInMemoryDatabases(): void
    {
        if (RefreshDatabaseState::$inMemoryConnections === []) {
            return;
        }

        $database = $this->app->make('db');
        $defaultConnection = $this->app->make('config')->string('database.default');

        foreach ($this->connectionsToTruncate() as $name) {
            $connectionName = $name ?? $defaultConnection;

            if (isset(RefreshDatabaseState::$inMemoryConnections[$connectionName])) {
                // The PDO outlives its original application; the dispatcher must not.
                $connection = $database->connection($name);

                if (! $connection instanceof PdoConnection) {
                    throw new LogicException('In-memory SQLite database testing requires a PDO-backed connection.');
                }

                $connection
                    ->setPdo(RefreshDatabaseState::$inMemoryConnections[$connectionName])
                    ->setEventDispatcher($this->app->make(Dispatcher::class));
            }
        }
    }

    /**
     * Cache the in-memory databases after migration.
     */
    protected function cacheInMemoryDatabases(): void
    {
        $database = $this->app->make('db');
        $config = $this->app->make('config');
        $defaultConnection = $config->string('database.default');

        foreach ($this->connectionsToTruncate() as $name) {
            if ($this->usingInMemoryDatabaseForTruncation($name)) {
                $connectionName = $name ?? $defaultConnection;
                $connection = $database->connection($name);

                if (! $connection instanceof PdoConnection) {
                    throw new LogicException('In-memory SQLite database testing requires a PDO-backed connection.');
                }

                RefreshDatabaseState::$inMemoryConnections[$connectionName] = $connection->getPdo();
            }
        }
    }

    /**
     * Determine if any connection being truncated uses an in-memory database.
     */
    protected function usingInMemoryDatabasesForTruncation(): bool
    {
        foreach ($this->connectionsToTruncate() as $name) {
            if ($this->usingInMemoryDatabaseForTruncation($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if an in-memory database being truncated is missing its cached PDO.
     */
    protected function hasMissingInMemoryDatabaseForTruncation(): bool
    {
        $defaultConnection = $this->app->make('config')->string('database.default');

        foreach ($this->connectionsToTruncate() as $name) {
            $connectionName = $name ?? $defaultConnection;

            if (
                $this->usingInMemoryDatabaseForTruncation($name)
                && ! isset(RefreshDatabaseState::$inMemoryConnections[$connectionName])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given connection uses an in-memory database.
     */
    protected function usingInMemoryDatabaseForTruncation(?string $name): bool
    {
        $config = $this->app->make('config');
        $name ??= $config->string('database.default');
        $configuration = $config->get("database.connections.{$name}");

        return is_array($configuration)
            && SQLiteDatabase::isInMemoryConfiguration($configuration);
    }

    /**
     * Truncate the database tables for all configured connections.
     */
    protected function truncateTablesForAllConnections(): void
    {
        $database = $this->app->make('db');

        (new Collection($this->connectionsToTruncate()))
            ->each(function ($name) use ($database) {
                $connection = $database->connection($name);

                $connection->getSchemaBuilder()->withoutForeignKeyConstraints(
                    fn () => $this->truncateTablesForConnection($connection, $name)
                );
            });
    }

    /**
     * Truncate the database tables for the given database connection.
     */
    protected function truncateTablesForConnection(ConnectionInterface $connection, ?string $name): void
    {
        $dispatcher = $connection->getEventDispatcher();

        $connection->unsetEventDispatcher();

        (new Collection($this->getAllTablesForConnection($connection, $name)))
            ->when(
                $this->tablesToTruncate($connection, $name),
                function (Collection $tables, array $tablesToTruncate) {
                    return $tables->filter(fn (array $table) => $this->tableExistsIn($table, $tablesToTruncate));
                },
                function (Collection $tables) use ($connection, $name) {
                    $exceptTables = $this->exceptTables($connection, $name);

                    return $tables->reject(fn (array $table) => $this->tableExistsIn($table, $exceptTables));
                }
            )
            ->each(function (array $table) use ($connection) {
                $connection->withoutTablePrefix(function ($connection) use ($table) {
                    $table = $connection->table($table['schema_qualified_name']);

                    if ($table->exists()) {
                        $table->truncate();
                    }
                });
            });

        $connection->setEventDispatcher($dispatcher);
    }

    /**
     * Get all the tables that belong to the connection.
     */
    protected function getAllTablesForConnection(ConnectionInterface $connection, ?string $name): array
    {
        if (isset(static::$allTables[$name])) {
            return static::$allTables[$name];
        }

        $schema = $connection->getSchemaBuilder();

        return static::$allTables[$name] = Arr::from($schema->getTables($schema->getCurrentSchemaListing()));
    }

    /**
     * Determine if a table exists in the given list, with or without its schema.
     */
    protected function tableExistsIn(array $table, array $tables): bool
    {
        return $table['schema']
            ? ! empty(array_intersect([$table['name'], $table['schema_qualified_name']], $tables))
            : in_array($table['name'], $tables);
    }

    /**
     * The database connections that should have their tables truncated.
     */
    protected function connectionsToTruncate(): array
    {
        return property_exists($this, 'connectionsToTruncate')
            ? $this->connectionsToTruncate
            : [null];
    }

    /**
     * Get the tables that should be truncated.
     */
    protected function tablesToTruncate(ConnectionInterface $connection, ?string $connectionName): ?array
    {
        return property_exists($this, 'tablesToTruncate') && is_array($this->tablesToTruncate)
            ? $this->tablesToTruncate[$connectionName] ?? $this->tablesToTruncate
            : null;
    }

    /**
     * Get the tables that should not be truncated.
     */
    protected function exceptTables(ConnectionInterface $connection, ?string $connectionName): array
    {
        $migrationsTable = $connection->getTablePrefix()
            . $this->app->make('config')->string('database.migrations.table');

        return property_exists($this, 'exceptTables') && is_array($this->exceptTables)
            ? array_merge(
                $this->exceptTables[$connectionName] ?? $this->exceptTables,
                [$migrationsTable],
            )
            : [$migrationsTable];
    }

    /**
     * Perform any work that should take place before the database has started truncating.
     */
    protected function beforeTruncatingDatabase(): void
    {
    }

    /**
     * Perform any work that should take place once the database has finished truncating.
     */
    protected function afterTruncatingDatabase(): void
    {
    }
}
