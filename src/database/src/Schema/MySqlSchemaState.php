<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Exception;
use Hypervel\Database\Connection;
use Hypervel\Database\MySqlConnection;
use Override;
use Pdo\Mysql;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

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
        $sql = $this->files->get($path);
        $length = strlen($sql);
        $offset = 0;
        $result = '';
        $sqlMode = '';

        while ($offset < $length) {
            $end = strpos($sql, "\n", $offset);
            $end = $end === false ? $length : $end + 1;
            $line = substr($sql, $offset, $end - $offset);

            if (preg_match('/^DELIMITER (\S+)/', $line, $matches) && $matches[1] !== ';') {
                $end = $this->skipDumpDelimitedStatements($sql, $end, $matches[1], $sqlMode);
                $result .= substr($sql, $offset, $end - $offset);
            } else {
                if (preg_match('/^(?:\/\*(?:!|M!)\d+\s*)?SET\b[^\r\n]*?\bSQL_MODE\s*=\s*[\'"]([A-Z0-9_,]*)[\'"]/i', $line, $matches)) {
                    $sqlMode = $matches[1];
                }

                // Native dumps put the counter here; a global replacement also changes SQL literals.
                $result .= preg_replace('/^\)[^\r\n]*? ENGINE=\w+\K AUTO_INCREMENT=\d+/', '', $line);
            }

            $offset = $end;
        }

        $this->files->put($path, $result);
    }

    /**
     * Find the end of an authored routine, trigger or event section in a native dump.
     */
    protected function skipDumpDelimitedStatements(string $sql, int $offset, string $delimiter, string $sqlMode): int
    {
        $length = strlen($sql);
        $quote = null;
        $executableComment = false;
        $statementStart = true;
        $modes = explode(',', strtoupper($sqlMode));
        $noBackslashEscapes = in_array('NO_BACKSLASH_ESCAPES', $modes, true);
        $ansiQuotes = in_array('ANSI_QUOTES', $modes, true) || in_array('ANSI', $modes, true);

        while ($offset < $length) {
            $character = $sql[$offset];
            $next = $sql[$offset + 1] ?? '';

            if ($quote !== null) {
                if ($character === '\\' && ! $noBackslashEscapes && ($quote === "'" || ($quote === '"' && ! $ansiQuotes))) {
                    $offset += 2;
                    continue;
                }

                if ($character === $quote) {
                    if ($next === $quote) {
                        $offset += 2;
                        continue;
                    }

                    $quote = null;
                }

                ++$offset;
                continue;
            }

            if (ctype_space($character)) {
                ++$offset;
                continue;
            }

            if ($character === '#' || ($character === '-' && $next === '-' && (! isset($sql[$offset + 2]) || ord($sql[$offset + 2]) <= 32))) {
                $end = strpos($sql, "\n", $offset);
                $offset = $end === false ? $length : $end + 1;
                continue;
            }

            if ($character === '/' && $next === '*') {
                if (preg_match('/\G\/\*(?:!|M!)\d*/', $sql, $matches, 0, $offset)) {
                    $executableComment = true;
                    $offset += strlen($matches[0]);
                } else {
                    $end = strpos($sql, '*/', $offset + 2);
                    $offset = $end === false ? $length : $end + 2;
                }

                continue;
            }

            if ($executableComment && $character === '*' && $next === '/') {
                $executableComment = false;
                $offset += 2;
                continue;
            }

            if (! $executableComment && substr_compare($sql, $delimiter, $offset, strlen($delimiter)) === 0) {
                $offset += strlen($delimiter);
                $statementStart = true;
                continue;
            }

            if ($statementStart) {
                if (! $executableComment && preg_match('/\GDELIMITER (\S+)[^\r\n]*(?:\r?\n|$)/', $sql, $matches, 0, $offset)) {
                    $delimiter = $matches[1];
                    $offset += strlen($matches[0]);

                    if ($delimiter === ';') {
                        return $offset;
                    }

                    continue;
                }

                // Each authored body has a literal mode assignment; generated restores need no saved-mode map.
                if (preg_match('/\GSET\s+(?:SESSION\s+)?SQL_MODE\s*=\s*[\'"]([A-Z0-9_,]*)[\'"]/i', $sql, $matches, 0, $offset)) {
                    $modes = explode(',', strtoupper($matches[1]));
                    $noBackslashEscapes = in_array('NO_BACKSLASH_ESCAPES', $modes, true);
                    $ansiQuotes = in_array('ANSI_QUOTES', $modes, true) || in_array('ANSI', $modes, true);
                }

                $statementStart = false;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            }

            ++$offset;
        }

        throw new RuntimeException('The schema dump has an unterminated DELIMITER section.');
    }

    /**
     * Append the migration data to the schema dump.
     */
    protected function appendMigrationData(string $path): void
    {
        $process = $this->executeDumpProcess($this->makeProcess(
            $this->baseDumpCommand() . ' "${:HYPERVEL_LOAD_MIGRATION_TABLE}" --no-create-info --skip-extended-insert --skip-routines --compact --complete-insert'
        ), null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_MIGRATION_TABLE' => $this->getMigrationTable(),
        ]));

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

        $ssl = $this->sslOptions($config);

        if ($ssl['requested']) {
            if ($versionInfo['isMariaDb'] && ! $ssl['verify']) {
                throw new RuntimeException('The MariaDB command-line client cannot require TLS without certificate verification. Enable certificate verification and configure a trusted SSL CA or CA directory.');
            }

            if (! $versionInfo['isMariaDb'] && version_compare($versionInfo['version'], '5.7.11', '<')) {
                throw new RuntimeException('Schema dump and load with TLS require the MySQL command-line client version 5.7.11 or later.');
            }

            foreach (['ca', 'cert', 'key', 'capath', 'cipher'] as $option) {
                if ($ssl[$option] !== null) {
                    $value .= sprintf(' --ssl-%s="${:HYPERVEL_LOAD_SSL_%s}"', $option, strtoupper($option));
                }
            }

            $value .= $versionInfo['isMariaDb']
                ? ' --ssl --ssl-verify-server-cert'
                : ($ssl['verify'] ? ' --ssl-mode=VERIFY_IDENTITY' : ' --ssl-mode=REQUIRED');
        } elseif ($versionInfo['isMariaDb'] || ! $ssl['verify']) {
            $value .= ! $versionInfo['isMariaDb'] && version_compare($versionInfo['version'], '5.7.11', '>=')
                ? ' --ssl-mode=DISABLED'
                : ' --ssl=off';
        }

        return $value;
    }

    /**
     * Resolve PDO's TLS intent and PHP's certificate trust paths for the native client.
     *
     * @param array<string, mixed> $config
     * @return array{requested: bool, verify: bool, ca: ?string, cert: ?string, key: ?string, capath: ?string, cipher: ?string}
     */
    protected function sslOptions(array $config): array
    {
        $options = $config['options'] ?? [];
        $ssl = [
            'ca' => isset($options[Mysql::ATTR_SSL_CA]) ? (string) $options[Mysql::ATTR_SSL_CA] : null,
            'cert' => isset($options[Mysql::ATTR_SSL_CERT]) ? (string) $options[Mysql::ATTR_SSL_CERT] : null,
            'key' => isset($options[Mysql::ATTR_SSL_KEY]) ? (string) $options[Mysql::ATTR_SSL_KEY] : null,
            'capath' => isset($options[Mysql::ATTR_SSL_CAPATH]) ? (string) $options[Mysql::ATTR_SSL_CAPATH] : null,
            'cipher' => isset($options[Mysql::ATTR_SSL_CIPHER]) ? (string) $options[Mysql::ATTR_SSL_CIPHER] : null,
        ];
        $ssl['requested'] = array_filter($ssl, static fn (?string $value): bool => $value !== null) !== [];
        $ssl['verify'] = (bool) ($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? true);

        if (! $ssl['requested'] || ! $ssl['verify']) {
            $ssl['ca'] = $ssl['capath'] = null;

            return $ssl;
        }

        $ssl['ca'] ??= ini_get('openssl.cafile') ?: null;
        $ssl['capath'] ??= ini_get('openssl.capath') ?: null;

        // PHP uses system trust only when neither connection options nor INI selected a trust store.
        if ($ssl['ca'] === null && $ssl['capath'] === null) {
            $locations = openssl_get_cert_locations();
            $file = getenv($locations['default_cert_file_env']);
            $directory = getenv($locations['default_cert_dir_env']);
            $file = $file === false ? $locations['default_cert_file'] : $file;
            $directory = $directory === false ? $locations['default_cert_dir'] : $directory;
            $ssl['ca'] = is_file($file) ? $file : null;
            $ssl['capath'] = is_dir($directory) ? $directory : null;
        }

        return $ssl;
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[Override]
    protected function baseVariables(array $config): array
    {
        $config['host'] ??= '';
        $ssl = $this->sslOptions($config);

        return array_map(strval(...), [
            'HYPERVEL_LOAD_SOCKET' => $config['unix_socket'] ?? '',
            'HYPERVEL_LOAD_HOST' => is_array($config['host']) ? $config['host'][0] : $config['host'],
            'HYPERVEL_LOAD_PORT' => $config['port'] ?? '',
            'HYPERVEL_LOAD_USER' => $config['username'],
            'HYPERVEL_LOAD_PASSWORD' => $config['password'] ?? '',
            'HYPERVEL_LOAD_DATABASE' => $config['database'],
            'HYPERVEL_LOAD_SSL_CA' => $ssl['ca'] ?? '',
            'HYPERVEL_LOAD_SSL_CERT' => $ssl['cert'] ?? '',
            'HYPERVEL_LOAD_SSL_KEY' => $ssl['key'] ?? '',
            'HYPERVEL_LOAD_SSL_CAPATH' => $ssl['capath'] ?? '',
            'HYPERVEL_LOAD_SSL_CIPHER' => $ssl['cipher'] ?? '',
        ]);
    }

    /**
     * Execute the given dump process.
     *
     * @param array<string, string> $variables
     *
     * @throws Throwable
     */
    protected function executeDumpProcess(Process $process, ?callable $output, array $variables, int $depth = 0): Process
    {
        if ($depth > 30) {
            throw new Exception('Dump execution exceeded maximum depth of 30.');
        }

        if ($process->setTimeout(null)->run($this->guardProcessOutput($process, $output), $variables) === 0) {
            return $process;
        }

        $command = $process->getCommandLine();
        $retryCommand = $command;
        $errorOutput = $process->getErrorOutput();

        // Exception messages include the command; only the client's diagnostic proves a flag is unsupported.
        foreach (['--column-statistics=0', '--set-gtid-purged=OFF'] as $flag) {
            if (str_contains($errorOutput, "unknown variable '" . substr($flag, 2) . "'")) {
                $retryCommand = str_replace(' ' . $flag, '', $retryCommand);
            }
        }

        if ($retryCommand === $command) {
            throw new ProcessFailedException($process);
        }

        return $this->executeDumpProcess($this->makeProcess($retryCommand), $output, $variables, $depth + 1);
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
