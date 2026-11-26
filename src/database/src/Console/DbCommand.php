<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Database\ConfigurationUrlParser;
use Hypervel\Database\DatabaseCliConfiguration;
use Hypervel\Database\DatabaseCliManager;
use Hypervel\Support\Arr;
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

    // Laravel's --pooled option is omitted; select external pooler endpoints by connection name.

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
        $configuration = $this->hypervel->make(DatabaseCliManager::class)->resolve($connection);

        if ($configuration === null) {
            $command = $this->getCommand($connection);

            // MySQL sockets and PostgreSQL without a host key connect locally, as the connector does. An empty
            // host list or an unselected read / write split has no endpoint.
            $requiresHost = match ($connection['driver']) {
                'sqlite' => false,
                'mysql', 'mariadb' => empty($connection['unix_socket']) || array_key_exists('host', $connection),
                default => array_key_exists('host', $connection) || isset($connection['read']) || isset($connection['write']),
            };

            if ($requiresHost && ! isset($connection['host'])) {
                $this->components->error('No host specified for this database connection.');
                $this->line('  Use the <options=bold>[--read]</> and <options=bold>[--write]</> options to specify a read or write connection.');
                $this->newLine();

                return self::FAILURE;
            }

            $configuration = new DatabaseCliConfiguration(
                $command,
                $this->commandArguments($connection),
                $this->commandEnvironment($connection) ?? [],
            );
        }

        try {
            // Container resolution lets tests replace the process without class overloading.
            $this->hypervel->make(Process::class, [
                'command' => array_merge([$configuration->command], $configuration->arguments),
                'env' => $configuration->environment,
            ])->setTimeout(null)->setTty(true)->mustRun(function ($type, $buffer) {
                $this->output->write($buffer);
            });
        } catch (ProcessFailedException $e) {
            if ($e->getProcess()->getExitCode() !== 127) {
                throw $e;
            }

            $this->error("{$configuration->command} not found in path.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Get the database connection configuration.
     *
     * @throws UnexpectedValueException
     */
    public function getConnection(): array
    {
        $config = $this->hypervel->make('config');
        $connectionName = $this->argument('connection') ?? $config->string('database.default');
        $connection = $config->array("database.connections.{$connectionName}", []);

        if (empty($connection)) {
            throw new UnexpectedValueException("Invalid database connection [{$connectionName}].");
        }

        if (! empty($connection['url'])) {
            $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        }

        if ($this->option('read')) {
            $connection = $this->mergeConnectionConfiguration($connection, 'read');
        } elseif ($this->option('write')) {
            $connection = $this->mergeConnectionConfiguration($connection, 'write');
        }

        if (is_array($connection['host'] ?? null)) {
            $connection['host'] = $connection['host'][0] ?? null;
        }

        return $connection;
    }

    /**
     * Merge a read / write connection configuration for the CLI client.
     */
    protected function mergeConnectionConfiguration(array $connection, string $type): array
    {
        if (empty($connection[$type])) {
            return Arr::except($connection, ['read', 'write']);
        }

        $merge = $connection[$type];

        if (isset($merge[0]) && is_array($merge[0])) {
            $merge = $merge[0];
        }

        if (! empty($merge['url'])) {
            $merge = (new ConfigurationUrlParser)->parseConfiguration($merge);
        }

        if (is_array($merge['host'] ?? null)) {
            $merge['host'] = $merge['host'][0] ?? null;
        }

        $connection = array_merge($connection, $merge);

        if (is_array($connection['host'] ?? null)) {
            $connection['host'] = $connection['host'][0] ?? null;
        }

        return Arr::except($connection, ['read', 'write']);
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
        return match ($connection['driver']) {
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'pgsql' => 'psql',
            'sqlite' => 'sqlite3',
            default => throw new UnexpectedValueException(
                "Unsupported database CLI driver [{$connection['driver']}]. Register a resolver using DatabaseCliManager::extend()."
            ),
        };
    }

    /**
     * Get the arguments for the MySQL CLI.
     */
    protected function getMysqlArguments(array $connection): array
    {
        // Like the connector, a configured socket replaces the TCP host and port. The explicit host
        // and protocol keep a client option file from selecting TCP instead.
        $transport = empty($connection['unix_socket'])
            ? $this->getOptionalArguments([
                'host' => '--host=' . ($connection['host'] ?? ''),
                'port' => '--port=' . ($connection['port'] ?? ''),
            ], $connection)
            : ['--host=localhost', '--protocol=SOCKET', '--socket=' . $connection['unix_socket']];

        return array_merge($transport, $this->getOptionalArguments([
            'username' => '--user=' . ($connection['username'] ?? ''),
            'charset' => '--default-character-set=' . ($connection['charset'] ?? ''),
        ], $connection), [$connection['database']]);
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
     * Get the environment variables for the MySQL CLI.
     */
    protected function getMysqlEnvironment(array $connection): ?array
    {
        return array_merge(...$this->getOptionalArguments([
            'password' => ['MYSQL_PWD' => $connection['password'] ?? ''],
        ], $connection));
    }

    /**
     * Get the environment variables for the MariaDB CLI.
     */
    protected function getMariaDbEnvironment(array $connection): ?array
    {
        return $this->getMysqlEnvironment($connection);
    }

    /**
     * Get the environment variables for the Postgres CLI.
     */
    protected function getPgsqlEnvironment(array $connection): ?array
    {
        return array_merge(...$this->getOptionalArguments([
            'username' => ['PGUSER' => $connection['username'] ?? ''],
            'host' => ['PGHOST' => $connection['host'] ?? ''],
            'port' => ['PGPORT' => $connection['port'] ?? ''],
            'password' => ['PGPASSWORD' => $connection['password'] ?? ''],
        ], $connection));
    }

    /**
     * Get the optional arguments based on the connection configuration.
     */
    protected function getOptionalArguments(array $args, array $connection): array
    {
        return array_values(array_filter($args, function ($key) use ($connection) {
            return isset($connection[$key]) && $connection[$key] !== '';
        }, ARRAY_FILTER_USE_KEY));
    }
}
