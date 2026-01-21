<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Exception;
use Hypervel\Database\Connection;
use Hypervel\Support\Str;
use Symfony\Component\Process\Process;

class MySqlSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     */
    #[\Override]
    public function dump(Connection $connection, string $path): void
    {
        $this->executeDumpProcess($this->makeProcess(
            $this->baseDumpCommand().' --routines --result-file="${:LARAVEL_LOAD_PATH}" --no-data'
        ), $this->output, array_merge($this->baseVariables($this->connection->getConfig()), [
            'LARAVEL_LOAD_PATH' => $path,
        ]));

        $this->removeAutoIncrementingState($path);

        if ($this->hasMigrationTable()) {
            $this->appendMigrationData($path);
        }
    }

    /**
     * Remove the auto-incrementing state from the given schema dump.
     */
    protected function removeAutoIncrementingState(string $path): void
    {
        $this->files->put($path, preg_replace(
            '/\s+AUTO_INCREMENT=[0-9]+/iu',
            '',
            $this->files->get($path)
        ));
    }

    /**
     * Append the migration data to the schema dump.
     */
    protected function appendMigrationData(string $path): void
    {
        $process = $this->executeDumpProcess($this->makeProcess(
            $this->baseDumpCommand().' '.$this->getMigrationTable().' --no-create-info --skip-extended-insert --skip-routines --compact --complete-insert'
        ), null, array_merge($this->baseVariables($this->connection->getConfig()), [
            //
        ]));

        $this->files->append($path, $process->getOutput());
    }

    /**
     * Load the given schema file into the database.
     */
    #[\Override]
    public function load(string $path): void
    {
        $command = 'mysql '.$this->connectionString().' --database="${:LARAVEL_LOAD_DATABASE}" < "${:LARAVEL_LOAD_PATH}"';

        $process = $this->makeProcess($command)->setTimeout(null);

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'LARAVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the base dump command arguments for MySQL as a string.
     */
    protected function baseDumpCommand(): string
    {
        $command = 'mysqldump '.$this->connectionString().' --no-tablespaces --skip-add-locks --skip-comments --skip-set-charset --tz-utc --column-statistics=0';

        if (! $this->connection->isMaria()) {
            $command .= ' --set-gtid-purged=OFF';
        }

        return $command.' "${:LARAVEL_LOAD_DATABASE}"';
    }

    /**
     * Generate a basic connection string (--socket, --host, --port, --user, --password) for the database.
     */
    protected function connectionString(): string
    {
        $value = ' --user="${:LARAVEL_LOAD_USER}" --password="${:LARAVEL_LOAD_PASSWORD}"';

        $config = $this->connection->getConfig();

        $value .= $config['unix_socket'] ?? false
            ? ' --socket="${:LARAVEL_LOAD_SOCKET}"'
            : ' --host="${:LARAVEL_LOAD_HOST}" --port="${:LARAVEL_LOAD_PORT}"';

        /** @phpstan-ignore class.notFound */
        if (isset($config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA])) {
            $value .= ' --ssl-ca="${:LARAVEL_LOAD_SSL_CA}"';
        }

        // if (isset($config['options'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]) &&
        //     $config['options'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === false) {
        //     $value .= ' --ssl=off';
        // }

        return $value;
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[\Override]
    protected function baseVariables(array $config): array
    {
        $config['host'] ??= '';

        return [
            'LARAVEL_LOAD_SOCKET' => $config['unix_socket'] ?? '',
            'LARAVEL_LOAD_HOST' => is_array($config['host']) ? $config['host'][0] : $config['host'],
            'LARAVEL_LOAD_PORT' => $config['port'] ?? '',
            'LARAVEL_LOAD_USER' => $config['username'],
            'LARAVEL_LOAD_PASSWORD' => $config['password'] ?? '',
            'LARAVEL_LOAD_DATABASE' => $config['database'],
            'LARAVEL_LOAD_SSL_CA' => $config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA] ?? '', // @phpstan-ignore class.notFound
        ];
    }

    /**
     * Execute the given dump process.
     */
    protected function executeDumpProcess(Process $process, ?callable $output, array $variables, int $depth = 0): Process
    {
        if ($depth > 30) {
            throw new Exception('Dump execution exceeded maximum depth of 30.');
        }

        try {
            $process->setTimeout(null)->mustRun($output, $variables);
        } catch (Exception $e) {
            if (Str::contains($e->getMessage(), ['column-statistics', 'column_statistics'])) {
                return $this->executeDumpProcess(Process::fromShellCommandLine(
                    str_replace(' --column-statistics=0', '', $process->getCommandLine())
                ), $output, $variables, $depth + 1);
            }

            if (str_contains($e->getMessage(), 'set-gtid-purged')) {
                return $this->executeDumpProcess(Process::fromShellCommandLine(
                    str_replace(' --set-gtid-purged=OFF', '', $process->getCommandLine())
                ), $output, $variables, $depth + 1);
            }

            throw $e;
        }

        return $process;
    }
}
