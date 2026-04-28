<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Exception;
use Hypervel\Database\Connection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Support\Str;
use Override;
use PDO;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * @property MySqlConnection $connection
 */
class MySqlSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     */
    #[Override]
    public function dump(Connection $connection, string $path): void
    {
        $this->executeDumpProcess($this->makeProcess(
            $this->baseDumpCommand() . ' --routines --result-file="${:HYPERVEL_LOAD_PATH}" --no-data'
        ), $this->output, array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_PATH' => $path,
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
            $this->baseDumpCommand() . ' ' . $this->getMigrationTable() . ' --no-create-info --skip-extended-insert --skip-routines --compact --complete-insert'
        ), null, array_merge($this->baseVariables($this->connection->getConfig()), []));

        $this->files->append($path, $process->getOutput());
    }

    /**
     * Load the given schema file into the database.
     */
    #[Override]
    public function load(string $path): void
    {
        $versionInfo = $this->detectClientVersion();

        $command = 'mysql ' . $this->connectionString($versionInfo) . ' --database="${:HYPERVEL_LOAD_DATABASE}" < "${:HYPERVEL_LOAD_PATH}"';

        $process = $this->makeProcess($command)->setTimeout(null);

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the base dump command arguments for MySQL as a string.
     */
    protected function baseDumpCommand(): string
    {
        $versionInfo = $this->detectClientVersion();

        $command = 'mysqldump ' . $this->connectionString($versionInfo) . ' --no-tablespaces --skip-add-locks --skip-comments --skip-set-charset --tz-utc --column-statistics=0';

        if (! $this->connection->isMaria()) {
            $command .= ' --set-gtid-purged=OFF';
        }

        return $command . ' "${:HYPERVEL_LOAD_DATABASE}"';
    }

    /**
     * Generate a basic connection string (--socket, --host, --port, --user, --password) for the database.
     *
     * @param array{version: string, isMariaDb: bool} $versionInfo
     */
    protected function connectionString(array $versionInfo): string
    {
        $value = ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}"';

        $config = $this->connection->getConfig();

        $value .= $config['unix_socket'] ?? false
            ? ' --socket="${:HYPERVEL_LOAD_SOCKET}"'
            : ' --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}"';

        /* @phpstan-ignore class.notFound */
        if (isset($config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA])) {
            $value .= ' --ssl-ca="${:HYPERVEL_LOAD_SSL_CA}"';
        }

        /* @phpstan-ignore class.notFound */
        if (isset($config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CERT : PDO::MYSQL_ATTR_SSL_CERT])) {
            $value .= ' --ssl-cert="${:HYPERVEL_LOAD_SSL_CERT}"';
        }

        /* @phpstan-ignore class.notFound */
        if (isset($config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_KEY : PDO::MYSQL_ATTR_SSL_KEY])) {
            $value .= ' --ssl-key="${:HYPERVEL_LOAD_SSL_KEY}"';
        }

        /** @phpstan-ignore class.notFound */
        $verifyCertOption = PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

        if (isset($config['options'][$verifyCertOption]) && $config['options'][$verifyCertOption] === false) {
            if (version_compare($versionInfo['version'], '5.7.11', '>=') && ! $versionInfo['isMariaDb']) {
                $value .= ' --ssl-mode=DISABLED';
            } else {
                $value .= ' --ssl=off';
            }
        }

        return $value;
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[Override]
    protected function baseVariables(array $config): array
    {
        $config['host'] ??= '';

        return [
            'HYPERVEL_LOAD_SOCKET' => $config['unix_socket'] ?? '',
            'HYPERVEL_LOAD_HOST' => is_array($config['host']) ? $config['host'][0] : $config['host'],
            'HYPERVEL_LOAD_PORT' => $config['port'] ?? '',
            'HYPERVEL_LOAD_USER' => $config['username'],
            'HYPERVEL_LOAD_PASSWORD' => $config['password'] ?? '',
            'HYPERVEL_LOAD_DATABASE' => $config['database'],
            'HYPERVEL_LOAD_SSL_CA' => $config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA] ?? '', // @phpstan-ignore class.notFound
            'HYPERVEL_LOAD_SSL_CERT' => $config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CERT : PDO::MYSQL_ATTR_SSL_CERT] ?? '', // @phpstan-ignore class.notFound
            'HYPERVEL_LOAD_SSL_KEY' => $config['options'][PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_KEY : PDO::MYSQL_ATTR_SSL_KEY] ?? '', // @phpstan-ignore class.notFound
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

    /**
     * Detect the MySQL client version.
     *
     * @return array{version: string, isMariaDb: bool}
     */
    protected function detectClientVersion(): array
    {
        [$version, $isMariaDb] = ['8.0.0', false];

        try {
            $versionOutput = $this->makeProcess('mysql --version')->mustRun()->getOutput();

            if (preg_match('/(\d+\.\d+\.\d+)/', $versionOutput, $matches)) {
                $version = $matches[1];
            }

            $isMariaDb = stripos($versionOutput, 'mariadb') !== false;
        } catch (ProcessFailedException) {
        }

        return [
            'version' => $version,
            'isMariaDb' => $isMariaDb,
        ];
    }
}
