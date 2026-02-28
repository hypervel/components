<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

abstract class SchemaState
{
    /**
     * The connection instance.
     */
    protected Connection $connection;

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * The name of the application's migration table.
     */
    protected string $migrationTable = 'migrations';

    /**
     * The process factory callback.
     */
    protected Closure $processFactory;

    /**
     * The output callable instance.
     */
    protected mixed $output;

    /**
     * Create a new dumper instance.
     */
    public function __construct(Connection $connection, ?Filesystem $files = null, ?callable $processFactory = null)
    {
        $this->connection = $connection;

        $this->files = $files ?: new Filesystem();

        $this->processFactory = $processFactory ?: function (...$arguments) {
            return Process::fromShellCommandline(...$arguments)->setTimeout(null);
        };

        $this->handleOutputUsing(function () {
        });
    }

    /**
     * Dump the database's schema into a file.
     */
    abstract public function dump(Connection $connection, string $path): void;

    /**
     * Load the given schema file into the database.
     */
    abstract public function load(string $path): void;

    /**
     * Get the base variables for a dump / load command.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    abstract protected function baseVariables(array $config): array;

    /**
     * Create a new process instance.
     */
    public function makeProcess(mixed ...$arguments): Process
    {
        return call_user_func($this->processFactory, ...$arguments);
    }

    /**
     * Determine if the current connection has a migration table.
     */
    public function hasMigrationTable(): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($this->migrationTable);
    }

    /**
     * Get the name of the application's migration table.
     */
    protected function getMigrationTable(): string
    {
        return $this->connection->getTablePrefix() . $this->migrationTable;
    }

    /**
     * Specify the name of the application's migration table.
     */
    public function withMigrationTable(string $table): static
    {
        $this->migrationTable = $table;

        return $this;
    }

    /**
     * Specify the callback that should be used to handle process output.
     */
    public function handleOutputUsing(callable $output): static
    {
        $this->output = $output;

        return $this;
    }
}
