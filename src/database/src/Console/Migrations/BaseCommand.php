<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\Command;
use Hypervel\Database\Connection;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\SQLiteDatabaseDoesNotExistException;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

abstract class BaseCommand extends Command
{
    /**
     * The migrator instance.
     */
    protected Migrator $migrator;

    /**
     * Get all of the migration paths.
     *
     * @return string[]
     */
    protected function getMigrationPaths(): array
    {
        // Here, we will check to see if a path option has been defined. If it has we will
        // use the path relative to the root of the installation folder so our database
        // migrations may be run for any customized path from within the application.
        if ($this->input->hasOption('path') && $this->option('path')) {
            return (new Collection($this->option('path')))->map(function ($path) {
                return ! $this->usingRealPath()
                    ? $this->hypervel->basePath() . '/' . $path
                    : $path;
            })->all();
        }

        return array_merge(
            $this->migrator->paths(),
            [$this->getMigrationPath()]
        );
    }

    /**
     * Determine if the given path(s) are pre-resolved "real" paths.
     */
    protected function usingRealPath(): bool
    {
        return $this->input->hasOption('realpath') && $this->option('realpath');
    }

    /**
     * Get the path to the migration directory.
     */
    protected function getMigrationPath(): string
    {
        return $this->hypervel->databasePath() . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * Inspect the given migration connections for missing physical databases.
     *
     * @param list<string> $connections
     * @return array<string, Throwable>
     */
    protected function inspectMigrationConnections(array $connections): array
    {
        $missingDatabases = [];

        foreach ($connections as $connection) {
            try {
                $this->migrator->usingConnection(
                    $connection,
                    fn (): bool => $this->migrator->repositoryExists(),
                );
            } catch (Throwable $throwable) {
                $cause = $this->findMissingDatabaseCause($connection, $throwable);

                if ($cause === null) {
                    throw $throwable;
                }

                $missingDatabases[$connection] = $cause;
            }
        }

        return $missingDatabases;
    }

    /**
     * Find a supported missing-database cause in the throwable chain.
     */
    protected function findMissingDatabaseCause(string $connectionName, Throwable $throwable): ?Throwable
    {
        $connection = null;

        for ($cause = $throwable; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof SQLiteDatabaseDoesNotExistException) {
                return $cause;
            }

            if (! $cause instanceof PDOException) {
                continue;
            }

            $connection ??= $this->migrator->resolveConnection($connectionName);

            if ($cause->getCode() === 1049
                && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return $cause;
            }

            if (($cause->errorInfo[0] ?? null) === '08006'
                && $connection->getDriverName() === 'pgsql'
                && Str::contains($cause->getMessage(), '"' . $connection->getDatabaseName() . '"')) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * Create and verify the classified missing databases.
     *
     * @param array<string, Throwable> $missingDatabases
     */
    protected function createMissingDatabases(array $missingDatabases): void
    {
        foreach ($missingDatabases as $connectionName => $cause) {
            $this->components->task(
                "Creating database [{$connectionName}]",
                fn () => $this->createMissingDatabase($connectionName, $cause),
            );
        }

        $unverified = $this->inspectMigrationConnections(array_keys($missingDatabases));

        if ($unverified !== []) {
            throw new RuntimeException(sprintf(
                'Database creation could not be verified for connections [%s].',
                implode(', ', array_keys($unverified)),
            ));
        }
    }

    /**
     * Create one classified missing database.
     */
    protected function createMissingDatabase(string $connectionName, Throwable $cause): void
    {
        if ($cause instanceof SQLiteDatabaseDoesNotExistException) {
            if (! touch($cause->path)) {
                throw new RuntimeException("SQLite database [{$cause->path}] could not be created.");
            }

            return;
        }

        $this->createMissingServerDatabase(
            $this->migrator->resolveConnection($connectionName)
        );
    }

    /**
     * Create a missing MySQL, MariaDB, or PostgreSQL database.
     */
    protected function createMissingServerDatabase(Connection $connection): void
    {
        // Use the resolved write configuration without mutating worker-global config.
        $adminConfig = $connection->getConfig();
        $database = $connection->getDatabaseName();
        $identifier = $connection->getQueryGrammar()->wrapIdentifier($database);
        $driver = $connection->getDriverName();

        [$adminDatabase, $createSql] = match ($driver) {
            'mysql', 'mariadb' => ['', "CREATE DATABASE IF NOT EXISTS {$identifier}"],
            'pgsql' => ['postgres', "CREATE DATABASE {$identifier}"],
            default => throw new RuntimeException(
                "Unsupported driver [{$driver}] for database creation."
            ),
        };

        $adminConfig['database'] = $adminDatabase;

        if ($driver === 'pgsql') {
            unset($adminConfig['connect_via_database']);
        }

        $factory = $this->hypervel->make('db.factory');
        $adminConnection = $factory->make($adminConfig, $connection->getName());

        try {
            if (! $adminConnection->unprepared($createSql)) {
                throw new RuntimeException("Database [{$database}] could not be created.");
            }
        } finally {
            $adminConnection->disconnect();
        }
    }
}
