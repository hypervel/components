<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Connection;
use Hypervel\Support\Collection;
use Override;

class PostgresSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     */
    #[Override]
    public function dump(Connection $connection, string $path): void
    {
        $commands = new Collection([
            $this->baseDumpCommand() . ' --schema-only > ' . $path,
        ]);

        if ($this->hasMigrationTable()) {
            $commands->push($this->baseDumpCommand() . ' -t ' . $this->getMigrationTable() . ' --data-only >> ' . $path);
        }

        $commands->map(function ($command, $path) {
            $this->makeProcess($command)->mustRun($this->output, array_merge($this->baseVariables($this->connection->getConfig()), [
                'LARAVEL_LOAD_PATH' => $path,
            ]));
        });
    }

    /**
     * Load the given schema file into the database.
     */
    #[Override]
    public function load(string $path): void
    {
        $command = 'pg_restore --no-owner --no-acl --clean --if-exists --host="${:LARAVEL_LOAD_HOST}" --port="${:LARAVEL_LOAD_PORT}" --username="${:LARAVEL_LOAD_USER}" --dbname="${:LARAVEL_LOAD_DATABASE}" "${:LARAVEL_LOAD_PATH}"';

        if (str_ends_with($path, '.sql')) {
            $command = 'psql --file="${:LARAVEL_LOAD_PATH}" --host="${:LARAVEL_LOAD_HOST}" --port="${:LARAVEL_LOAD_PORT}" --username="${:LARAVEL_LOAD_USER}" --dbname="${:LARAVEL_LOAD_DATABASE}"';
        }

        $process = $this->makeProcess($command);

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'LARAVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the name of the application's migration table.
     */
    #[Override]
    protected function getMigrationTable(): string
    {
        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($this->migrationTable, withDefaultSchema: true);

        return $schema . '.' . $this->connection->getTablePrefix() . $table;
    }

    /**
     * Get the base dump command arguments for PostgreSQL as a string.
     */
    protected function baseDumpCommand(): string
    {
        return 'pg_dump --no-owner --no-acl --host="${:LARAVEL_LOAD_HOST}" --port="${:LARAVEL_LOAD_PORT}" --username="${:LARAVEL_LOAD_USER}" --dbname="${:LARAVEL_LOAD_DATABASE}"';
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[Override]
    protected function baseVariables(array $config): array
    {
        $config['host'] ??= '';

        return [
            'LARAVEL_LOAD_HOST' => is_array($config['host']) ? $config['host'][0] : $config['host'],
            'LARAVEL_LOAD_PORT' => $config['port'] ?? '',
            'LARAVEL_LOAD_USER' => $config['username'],
            'PGPASSWORD' => $config['password'],
            'LARAVEL_LOAD_DATABASE' => $config['database'],
        ];
    }
}
