<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Support\ConfigurationUrlParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

#[AsCommand(name: 'db')]
class DbCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'db {connection? : The database connection that should be used}
               {--read : Connect to the read connection}
               {--write : Connect to the write connection}';

    /**
     * The console command description.
     */
    protected string $description = 'Start a new database CLI session';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->getConnection();

        if (! isset($connection['host']) && $connection['driver'] !== 'sqlite') {
            $this->components->error('No host specified for this database connection.');
            $this->line('  Use the <options=bold>[--read]</> and <options=bold>[--write]</> options to specify a read or write connection.');
            $this->newLine();

            return Command::FAILURE;
        }

        try {
            (new Process(
                array_merge([$command = $this->getCommand($connection)], $this->commandArguments($connection)),
                null,
                $this->commandEnvironment($connection)
            ))->setTimeout(null)->setTty(true)->mustRun(function ($type, $buffer) {
                $this->output->write($buffer);
            });
        } catch (ProcessFailedException $e) {
            throw_unless($e->getProcess()->getExitCode() === 127, $e);

            $this->error("{$command} not found in path.");

            return Command::FAILURE;
        }

        return 0;
    }

    /**
     * Get the database connection configuration.
     *
     * @throws UnexpectedValueException
     */
    public function getConnection(): array
    {
        $connection = $this->hypervel['config']['database.connections.'
            . (($db = $this->argument('connection')) ?? $this->hypervel['config']['database.default'])
        ];

        if (empty($connection)) {
            throw new UnexpectedValueException("Invalid database connection [{$db}].");
        }

        if (! empty($connection['url'])) {
            $connection = (new ConfigurationUrlParser())->parseConfiguration($connection);
        }

        if ($this->option('read')) {
            if (is_array($connection['read']['host'])) {
                $connection['read']['host'] = $connection['read']['host'][0];
            }

            $connection = array_merge($connection, $connection['read']);
        } elseif ($this->option('write')) {
            if (is_array($connection['write']['host'])) {
                $connection['write']['host'] = $connection['write']['host'][0];
            }

            $connection = array_merge($connection, $connection['write']);
        }

        return $connection;
    }

    /**
     * Get the arguments for the database client command.
     */
    public function commandArguments(array $connection): array
    {
        $driver = ucfirst($connection['driver']);

        return $this->{"get{$driver}Arguments"}($connection);
    }

    /**
     * Get the environment variables for the database client command.
     */
    public function commandEnvironment(array $connection): ?array
    {
        $driver = ucfirst($connection['driver']);

        if (method_exists($this, "get{$driver}Environment")) {
            return $this->{"get{$driver}Environment"}($connection);
        }

        return null;
    }

    /**
     * Get the database client command to run.
     */
    public function getCommand(array $connection): string
    {
        return [
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'pgsql' => 'psql',
            'sqlite' => 'sqlite3',
        ][$connection['driver']];
    }

    /**
     * Get the arguments for the MySQL CLI.
     */
    protected function getMysqlArguments(array $connection): array
    {
        $optionalArguments = [
            'password' => '--password=' . $connection['password'],
            'unix_socket' => '--socket=' . ($connection['unix_socket'] ?? ''),
            'charset' => '--default-character-set=' . ($connection['charset'] ?? ''),
        ];

        if (! $connection['password']) {
            unset($optionalArguments['password']);
        }

        return array_merge([
            '--host=' . $connection['host'],
            '--port=' . $connection['port'],
            '--user=' . $connection['username'],
        ], $this->getOptionalArguments($optionalArguments, $connection), [$connection['database']]);
    }

    /**
     * Get the arguments for the MariaDB CLI.
     */
    protected function getMariaDbArguments(array $connection): array
    {
        return $this->getMysqlArguments($connection);
    }

    /**
     * Get the arguments for the Postgres CLI.
     */
    protected function getPgsqlArguments(array $connection): array
    {
        return [$connection['database']];
    }

    /**
     * Get the arguments for the SQLite CLI.
     */
    protected function getSqliteArguments(array $connection): array
    {
        return [$connection['database']];
    }

    /**
     * Get the environment variables for the Postgres CLI.
     */
    protected function getPgsqlEnvironment(array $connection): ?array
    {
        return array_merge(...$this->getOptionalArguments([
            'username' => ['PGUSER' => $connection['username']],
            'host' => ['PGHOST' => $connection['host']],
            'port' => ['PGPORT' => $connection['port']],
            'password' => ['PGPASSWORD' => $connection['password']],
        ], $connection));
    }

    /**
     * Get the optional arguments based on the connection configuration.
     */
    protected function getOptionalArguments(array $args, array $connection): array
    {
        return array_values(array_filter($args, function ($key) use ($connection) {
            return ! empty($connection[$key]);
        }, ARRAY_FILTER_USE_KEY));
    }
}
