<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Connection;
use Hypervel\Support\Collection;
use Override;
use Symfony\Component\Process\Process;

class PostgresSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     */
    #[Override]
    public function dump(Connection $connection, string $path): void
    {
        $commands = new Collection([
            $this->baseDumpCommand() . ' --schema-only > "${:HYPERVEL_LOAD_PATH}"',
        ]);
        $variables = ['HYPERVEL_LOAD_PATH' => $path];

        if ($this->hasMigrationTable()) {
            $commands->push($this->baseDumpCommand() . ' -t "${:HYPERVEL_LOAD_MIGRATION_TABLE}" --data-only >> "${:HYPERVEL_LOAD_PATH}"');
            $variables['HYPERVEL_LOAD_MIGRATION_TABLE'] = $this->getMigrationTable();
        }

        $commands->map(function ($command) use ($variables) {
            $process = $this->makeProcess($command);

            $process->mustRun($this->guardProcessOutput($process, $this->output), array_merge($this->baseVariables($this->connection->getConfig()), $variables));
        });
    }

    /**
     * Load the given schema file into the database.
     */
    #[Override]
    public function load(string $path): void
    {
        $command = 'pg_restore --no-owner --no-acl --clean --if-exists --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --username="${:HYPERVEL_LOAD_USER}" --dbname="${:HYPERVEL_LOAD_DATABASE}" "${:HYPERVEL_LOAD_PATH}"';
        $output = null;

        if (str_ends_with($path, '.sql')) {
            $command = 'psql --file="${:HYPERVEL_LOAD_PATH}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --username="${:HYPERVEL_LOAD_USER}" --dbname="${:HYPERVEL_LOAD_DATABASE}"';

            // psql continues after statement errors with exit code 0; stderr carries the diagnostics.
            $output = function (string $type, string $buffer): void {
                if ($type === Process::ERR) {
                    ($this->output)($type, $buffer);
                }
            };
        }

        $process = $this->makeProcess($command);

        $process->mustRun($this->guardProcessOutput($process, $output), array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the name of the application's migration table.
     */
    #[Override]
    protected function getMigrationTable(): string
    {
        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($this->migrationTable, withDefaultSchema: true);

        // pg_dump folds unquoted names and treats unquoted dots and pattern characters specially.
        return '"' . str_replace('"', '""', $schema) . '"."'
            . str_replace('"', '""', $this->connection->getTablePrefix() . $table) . '"';
    }

    /**
     * Get the base dump command arguments for PostgreSQL as a string.
     */
    protected function baseDumpCommand(): string
    {
        return 'pg_dump --no-owner --no-acl --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --username="${:HYPERVEL_LOAD_USER}" --dbname="${:HYPERVEL_LOAD_DATABASE}"';
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[Override]
    protected function baseVariables(array $config): array
    {
        $config['host'] ??= '';

        $variables = [
            'HYPERVEL_LOAD_HOST' => is_array($config['host']) ? $config['host'][0] : $config['host'],
            'HYPERVEL_LOAD_PORT' => $config['port'] ?? '',
            'HYPERVEL_LOAD_USER' => $config['username'],
            'PGPASSWORD' => $config['password'],
        ];

        if (! empty($config['sslmode'])) {
            $variables['PGSSLMODE'] = $config['sslmode'];
        }

        $variables['HYPERVEL_LOAD_DATABASE'] = $config['database'];

        return array_map(strval(...), $variables);
    }
}
