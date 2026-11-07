<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Exception;
use Generator;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\Schema\MySqlSchemaState;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseMySqlSchemaStateTest extends TestCase
{
    #[TestWith([''])]
    #[TestWith(['MYSQL40,'])]
    public function testCounterCleanupPreservesLiteralsAndAuthoredStatements(string $modePrefix): void
    {
        // Adapted native output: keep the unwrapped NBE assignment when regenerating (MariaDB MDEV-30295).
        // The prefix checks mode names with digits, such as MariaDB's MYSQL40, both outside and inside a DELIMITER section.
        $sql = str_replace(
            "= 'NO_BACKSLASH_ESCAPES'",
            "= '{$modePrefix}NO_BACKSLASH_ESCAPES'",
            file_get_contents(__DIR__ . '/Fixtures/mysql-schema-counters.sql'),
        );
        $expected = str_replace([
            ') ENGINE=InnoDB AUTO_INCREMENT=20',
            ') /*!50100 TABLESPACE `example` */ ENGINE=InnoDB AUTO_INCREMENT=60',
        ], [
            ') ENGINE=InnoDB',
            ') /*!50100 TABLESPACE `example` */ ENGINE=InnoDB',
        ], $sql);
        $files = $this->createMock(Filesystem::class);
        $files->expects($this->once())->method('get')->with('/schema.sql')->willReturn($sql);
        $files->expects($this->once())->method('put')->with('/schema.sql', $expected);
        $state = new MySqlSchemaState($this->createStub(MySqlConnection::class), $files);

        (new ReflectionMethod($state, 'removeAutoIncrementingState'))->invoke($state, '/schema.sql');
    }

    public function testCounterCleanupRejectsAnUnterminatedAuthoredSection(): void
    {
        $files = $this->createMock(Filesystem::class);
        $files->method('get')->willReturn("DELIMITER ;;\nCREATE PROCEDURE example() SELECT 'unfinished");
        $files->expects($this->never())->method('put');
        $state = new MySqlSchemaState($this->createStub(MySqlConnection::class), $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The schema dump has an unterminated DELIMITER section.');

        (new ReflectionMethod($state, 'removeAutoIncrementingState'))->invoke($state, '/schema.sql');
    }

    public function testDumpOutputFailureStopsTheProcess(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo "progress"; usleep(5000000);']);
        $state = new MySqlSchemaState($this->createStub(MySqlConnection::class));
        $failure = new RuntimeException('Unable to write dump output.');
        $caught = null;

        try {
            try {
                (new ReflectionMethod($state, 'executeDumpProcess'))->invoke(
                    $state,
                    $process,
                    static fn () => throw $failure,
                    [],
                );
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($failure, $caught);
            $this->assertFalse($process->isRunning());
        } finally {
            $process->stop(0);
        }
    }

    #[DataProvider('provider')]
    public function testConnectionString(string $expectedConnectionString, array $expectedVariables, array $dbConfig): void
    {
        $expectedVariables += ['HYPERVEL_LOAD_SSL_CAPATH' => '', 'HYPERVEL_LOAD_SSL_CIPHER' => ''];
        $connection = $this->createStub(MySqlConnection::class);
        $connection->method('getConfig')->willReturn($dbConfig);

        $schemaState = new MySqlSchemaState($connection);

        $versionInfo = ['version' => '8.0.0', 'isMariaDb' => false];

        // test connectionString
        $method = new ReflectionMethod(get_class($schemaState), 'connectionString');
        $connString = $method->invoke($schemaState, $versionInfo);

        $this->assertEquals($expectedConnectionString, $connString);

        // test baseVariables
        $method = new ReflectionMethod(get_class($schemaState), 'baseVariables');
        $variables = $method->invoke($schemaState, $dbConfig);

        $this->assertSame($expectedVariables, $variables);
    }

    public static function provider(): Generator
    {
        yield 'default' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}"', [
                'HYPERVEL_LOAD_SOCKET' => '',
                'HYPERVEL_LOAD_HOST' => '127.0.0.1',
                'HYPERVEL_LOAD_PORT' => '',
                'HYPERVEL_LOAD_USER' => 'root',
                'HYPERVEL_LOAD_PASSWORD' => '',
                'HYPERVEL_LOAD_DATABASE' => 'forge',
                'HYPERVEL_LOAD_SSL_CA' => '',
                'HYPERVEL_LOAD_SSL_CERT' => '',
                'HYPERVEL_LOAD_SSL_KEY' => '',
            ], [
                'username' => 'root',
                'host' => '127.0.0.1',
                'database' => 'forge',
            ],
        ];

        yield 'integer port' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}"', [
                'HYPERVEL_LOAD_SOCKET' => '',
                'HYPERVEL_LOAD_HOST' => '127.0.0.1',
                'HYPERVEL_LOAD_PORT' => '3306',
                'HYPERVEL_LOAD_USER' => 'root',
                'HYPERVEL_LOAD_PASSWORD' => '',
                'HYPERVEL_LOAD_DATABASE' => 'forge',
                'HYPERVEL_LOAD_SSL_CA' => '',
                'HYPERVEL_LOAD_SSL_CERT' => '',
                'HYPERVEL_LOAD_SSL_KEY' => '',
            ], [
                'username' => 'root',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'forge',
            ],
        ];

        yield 'ssl_ca' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --ssl-ca="${:HYPERVEL_LOAD_SSL_CA}" --ssl-mode=VERIFY_IDENTITY', [
                'HYPERVEL_LOAD_SOCKET' => '',
                'HYPERVEL_LOAD_HOST' => '',
                'HYPERVEL_LOAD_PORT' => '',
                'HYPERVEL_LOAD_USER' => 'root',
                'HYPERVEL_LOAD_PASSWORD' => '',
                'HYPERVEL_LOAD_DATABASE' => 'forge',
                'HYPERVEL_LOAD_SSL_CA' => 'ssl.ca',
                'HYPERVEL_LOAD_SSL_CERT' => '',
                'HYPERVEL_LOAD_SSL_KEY' => '',
            ], [
                'username' => 'root',
                'database' => 'forge',
                'options' => [
                    Mysql::ATTR_SSL_CA => 'ssl.ca',
                ],
            ],
        ];

        yield 'ssl_cert_and_key' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --ssl-ca="${:HYPERVEL_LOAD_SSL_CA}" --ssl-cert="${:HYPERVEL_LOAD_SSL_CERT}" --ssl-key="${:HYPERVEL_LOAD_SSL_KEY}" --ssl-mode=VERIFY_IDENTITY', [
                'HYPERVEL_LOAD_SOCKET' => '',
                'HYPERVEL_LOAD_HOST' => '',
                'HYPERVEL_LOAD_PORT' => '',
                'HYPERVEL_LOAD_USER' => 'root',
                'HYPERVEL_LOAD_PASSWORD' => '',
                'HYPERVEL_LOAD_DATABASE' => 'forge',
                'HYPERVEL_LOAD_SSL_CA' => 'ssl.ca',
                'HYPERVEL_LOAD_SSL_CERT' => '/path/to/client-cert.pem',
                'HYPERVEL_LOAD_SSL_KEY' => '/path/to/client-key.pem',
            ], [
                'username' => 'root',
                'database' => 'forge',
                'options' => [
                    Mysql::ATTR_SSL_CA => 'ssl.ca',
                    Mysql::ATTR_SSL_CERT => '/path/to/client-cert.pem',
                    Mysql::ATTR_SSL_KEY => '/path/to/client-key.pem',
                ],
            ],
        ];

        yield 'no_ssl' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --ssl-mode=DISABLED', [
                'HYPERVEL_LOAD_SOCKET' => '',
                'HYPERVEL_LOAD_HOST' => '',
                'HYPERVEL_LOAD_PORT' => '',
                'HYPERVEL_LOAD_USER' => 'root',
                'HYPERVEL_LOAD_PASSWORD' => '',
                'HYPERVEL_LOAD_DATABASE' => 'forge',
                'HYPERVEL_LOAD_SSL_CA' => '',
                'HYPERVEL_LOAD_SSL_CERT' => '',
                'HYPERVEL_LOAD_SSL_KEY' => '',
            ], [
                'username' => 'root',
                'database' => 'forge',
                'options' => [
                    Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
                ],
            ],
        ];

        yield 'unix socket' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --socket="${:HYPERVEL_LOAD_SOCKET}"', [
                'HYPERVEL_LOAD_SOCKET' => '/tmp/mysql.sock',
                'HYPERVEL_LOAD_HOST' => '',
                'HYPERVEL_LOAD_PORT' => '',
                'HYPERVEL_LOAD_USER' => 'root',
                'HYPERVEL_LOAD_PASSWORD' => '',
                'HYPERVEL_LOAD_DATABASE' => 'forge',
                'HYPERVEL_LOAD_SSL_CA' => '',
                'HYPERVEL_LOAD_SSL_CERT' => '',
                'HYPERVEL_LOAD_SSL_KEY' => '',
            ], [
                'username' => 'root',
                'database' => 'forge',
                'unix_socket' => '/tmp/mysql.sock',
            ],
        ];
    }

    #[DataProvider('tlsOptions')]
    public function testTlsIntentAndAdditionalOptions(array $options, array $version, string $arguments, array $variables): void
    {
        $config = ['username' => 'root', 'database' => 'app', 'options' => $options];
        $connection = $this->createStub(MySqlConnection::class);
        $connection->method('getConfig')->willReturn($config);
        $state = new MySqlSchemaState($connection);

        $command = (new ReflectionMethod($state, 'connectionString'))->invoke($state, $version);
        $actualVariables = (new ReflectionMethod($state, 'baseVariables'))->invoke($state, $config);

        $this->assertSame(' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}"' . $arguments, $command);
        foreach ($variables as $name => $value) {
            $this->assertSame($value, $actualVariables[$name]);
        }
    }

    /**
     * Provide encryption, verification and native-client compatibility boundaries.
     */
    public static function tlsOptions(): array
    {
        return [
            'encryption without verification' => [[
                Mysql::ATTR_SSL_CA => 'unused-ca.pem',
                Mysql::ATTR_SSL_CAPATH => 'unused-certs',
                Mysql::ATTR_SSL_CERT => 'client.pem',
                Mysql::ATTR_SSL_KEY => 'client.key',
                Mysql::ATTR_SSL_CIPHER => 'ECDHE-RSA-AES256-GCM-SHA384',
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
            ], ['version' => '8.4.0', 'isMariaDb' => false],
                ' --ssl-cert="${:HYPERVEL_LOAD_SSL_CERT}" --ssl-key="${:HYPERVEL_LOAD_SSL_KEY}" --ssl-cipher="${:HYPERVEL_LOAD_SSL_CIPHER}" --ssl-mode=REQUIRED', [
                    'HYPERVEL_LOAD_SSL_CA' => '',
                    'HYPERVEL_LOAD_SSL_CAPATH' => '',
                    'HYPERVEL_LOAD_SSL_CERT' => 'client.pem',
                    'HYPERVEL_LOAD_SSL_KEY' => 'client.key',
                    'HYPERVEL_LOAD_SSL_CIPHER' => 'ECDHE-RSA-AES256-GCM-SHA384',
                ],
            ],
            'CA directory and cipher' => [[
                Mysql::ATTR_SSL_CA => 'ca.pem',
                Mysql::ATTR_SSL_CAPATH => 'certificates',
                Mysql::ATTR_SSL_CIPHER => 'ECDHE-RSA-AES256-GCM-SHA384',
            ], ['version' => '8.4.0', 'isMariaDb' => false],
                ' --ssl-ca="${:HYPERVEL_LOAD_SSL_CA}" --ssl-capath="${:HYPERVEL_LOAD_SSL_CAPATH}" --ssl-cipher="${:HYPERVEL_LOAD_SSL_CIPHER}" --ssl-mode=VERIFY_IDENTITY', [
                    'HYPERVEL_LOAD_SSL_CA' => 'ca.pem',
                    'HYPERVEL_LOAD_SSL_CAPATH' => 'certificates',
                    'HYPERVEL_LOAD_SSL_CIPHER' => 'ECDHE-RSA-AES256-GCM-SHA384',
                ],
            ],
            'old client without TLS' => [[Mysql::ATTR_SSL_VERIFY_SERVER_CERT => 0],
                ['version' => '5.7.10', 'isMariaDb' => false], ' --ssl=off', [],
            ],
        ];
    }

    #[DataProvider('unsupportedTlsClients')]
    public function testLoadRejectsUnsupportedTlsBeforeLaunchingTheClient(string $versionOutput, array $options, string $message): void
    {
        $connection = $this->createStub(MySqlConnection::class);
        $connection->method('getConfig')->willReturn(['options' => $options]);
        $version = $this->createMock(Process::class);
        $version->expects($this->once())->method('mustRun')->willReturnSelf();
        $version->method('getOutput')->willReturn($versionOutput);
        $state = new MySqlSchemaState($connection, processFactory: function (string $command) use ($version): Process {
            $this->assertSame('mysql --version', $command);

            return $version;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $state->load('/schema.sql');
    }

    /**
     * Provide native clients that cannot honor the requested TLS policy.
     */
    public static function unsupportedTlsClients(): array
    {
        return [
            'MariaDB without verification' => ['mysql from 11.8.3-MariaDB', [
                Mysql::ATTR_SSL_CA => 'ca.pem',
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
            ], 'The MariaDB command-line client cannot require TLS without certificate verification.'],
            'old Oracle client with TLS' => ['mysql Ver 5.7.10', [
                Mysql::ATTR_SSL_CA => 'ca.pem',
            ], 'Schema dump and load with TLS require the MySQL command-line client version 5.7.11 or later.'],
        ];
    }

    #[DataProvider('trustSettings')]
    public function testTrustPathsRespectConnectionIniAndSystemPrecedence(string $iniFile, string $iniDirectory): void
    {
        $script = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';

            use Hypervel\Database\MySqlConnection;
            use Hypervel\Database\Schema\MySqlSchemaState;
            use Pdo\Mysql;

            $state = new MySqlSchemaState(new MySqlConnection(static fn () => throw new LogicException('No connection is needed.')));
            $method = new ReflectionMethod($state, 'sslOptions');
            $results = [];
            foreach ([
                'defaults' => [Mysql::ATTR_SSL_CERT => 'client.pem'],
                'connection file' => [Mysql::ATTR_SSL_CA => 'connection-ca.pem'],
                'connection directory' => [Mysql::ATTR_SSL_CAPATH => 'connection-certs'],
                'both explicit' => [Mysql::ATTR_SSL_CA => 'connection-ca.pem', Mysql::ATTR_SSL_CAPATH => 'connection-certs'],
                'plaintext' => [],
            ] as $name => $options) {
                $ssl = $method->invoke($state, ['options' => $options]);
                $results[$name] = [$ssl['requested'], $ssl['ca'], $ssl['capath']];
            }
            $locations = openssl_get_cert_locations();
            putenv($locations['default_cert_file_env'] . '=' . $argv[1] . '/missing-ca.pem');
            putenv($locations['default_cert_dir_env'] . '=' . $argv[1] . '/missing-certs');
            $ssl = $method->invoke($state, ['options' => [Mysql::ATTR_SSL_CERT => 'client.pem']]);
            $results['unavailable defaults'] = [$ssl['requested'], $ssl['ca'], $ssl['capath']];
            echo json_encode($results, JSON_THROW_ON_ERROR);
            PHP;
        $locations = openssl_get_cert_locations();
        $process = new Process([
            PHP_BINARY, '-d', 'openssl.cafile=' . $iniFile, '-d', 'openssl.capath=' . $iniDirectory,
            '-r', $script, dirname(__DIR__, 2),
        ], env: [
            $locations['default_cert_file_env'] => __FILE__,
            $locations['default_cert_dir_env'] => __DIR__,
        ], timeout: 10);
        $process->mustRun();

        $this->assertSame([
            'defaults' => [true, $iniFile ?: __FILE__, $iniDirectory ?: __DIR__],
            'connection file' => [true, 'connection-ca.pem', $iniDirectory ?: null],
            'connection directory' => [true, $iniFile ?: null, 'connection-certs'],
            'both explicit' => [true, 'connection-ca.pem', 'connection-certs'],
            'plaintext' => [false, null, null],
            'unavailable defaults' => [true, $iniFile ?: null, $iniDirectory ?: null],
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Provide isolated INI contexts for certificate-path resolution.
     */
    public static function trustSettings(): array
    {
        return [
            'system trust' => ['', ''],
            'INI trust' => ['ini-ca.pem', 'ini-certs'],
        ];
    }

    #[DataProvider('unsupportedDumpOptions')]
    public function testUnsupportedOptionsRetryThroughTheProcessFactory(array $unsupported, array $remaining): void
    {
        $script = '$unsupported = ' . var_export($unsupported, true) . ';' . <<<'PHP'
            $failed = false;
            foreach ($argv as $argument) {
                if (in_array($argument, $unsupported, true)) {
                    fwrite(STDERR, "mysqldump: unknown variable '" . substr($argument, 2) . "'\n");
                    $failed = true;
                }
            }
            if ($failed) { exit(7); }
            echo getenv('SCHEMA_DUMP_PROBE');
            PHP;
        $baseCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' --';
        $commands = [];
        $state = new MySqlSchemaState(
            $this->createStub(MySqlConnection::class),
            processFactory: static function (string $command) use (&$commands): Process {
                $commands[] = $command;

                return Process::fromShellCommandline($command);
            },
        );
        $process = $state->makeProcess($baseCommand . ' --column-statistics=0 --set-gtid-purged=OFF');
        $output = '';
        $result = (new ReflectionMethod($state, 'executeDumpProcess'))->invoke(
            $state,
            $process,
            static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            },
            ['SCHEMA_DUMP_PROBE' => 'factory environment'],
        );

        $this->assertSame([
            $baseCommand . ' --column-statistics=0 --set-gtid-purged=OFF',
            $baseCommand . ($remaining === [] ? '' : ' ' . implode(' ', $remaining)),
        ], $commands);
        $this->assertSame('factory environment', $result->getOutput());
        $this->assertStringContainsString('factory environment', $output);
    }

    /**
     * Provide native client diagnostic patterns with distinct removal behavior.
     *
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function unsupportedDumpOptions(): array
    {
        return [
            'both unsupported' => [['--column-statistics=0', '--set-gtid-purged=OFF'], []],
            'only statistics unsupported' => [['--column-statistics=0'], ['--set-gtid-purged=OFF']],
        ];
    }

    #[DataProvider('nonRetryableDumpFailures')]
    public function testDumpFailuresDoNotRetryWithoutAFlagToRemove(string $error, string $flags): void
    {
        $script = 'fwrite(STDERR, ' . var_export($error, true) . '); exit(1);';
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' --' . $flags;
        $commands = [];
        $state = new MySqlSchemaState(
            $this->createStub(MySqlConnection::class),
            processFactory: static function (string $command) use (&$commands): Process {
                $commands[] = $command;

                return Process::fromShellCommandline($command);
            },
        );
        $process = $state->makeProcess($command);
        $failure = null;

        try {
            (new ReflectionMethod($state, 'executeDumpProcess'))->invoke($state, $process, null, []);
        } catch (ProcessFailedException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(ProcessFailedException::class, $failure);
        $this->assertSame($process, $failure->getProcess());
        $this->assertSame($error, $process->getErrorOutput());
        $this->assertSame([$command], $commands);
    }

    /**
     * Provide ordinary failures and compatibility errors no retry can change.
     *
     * @return array<string, array{string, string}>
     */
    public static function nonRetryableDumpFailures(): array
    {
        return [
            'unrelated failure' => ["Unknown database 'column_statistics'", ' --column-statistics=0 --set-gtid-purged=OFF'],
            'option already removed' => ["mysqldump: unknown variable 'column-statistics=0'", ''],
        ];
    }

    public function testMigrationTableIsPassedAsOneLiteralArgument(): void
    {
        $connection = $this->createStub(MySqlConnection::class);
        $connection->method('getConfig')->willReturn(['host' => '127.0.0.1', 'username' => 'user', 'database' => 'app']);
        $connection->method('getTablePrefix')->willReturn('prefix_');
        $migrationTable = 'archive $migrations" one';

        $version = $this->createStub(Process::class);
        $version->method('mustRun')->willReturnSelf();
        $version->method('getOutput')->willReturn('mysql  Ver 8.0.0');
        $dump = $this->createMock(Process::class);
        $dump->method('setTimeout')->willReturnSelf();
        $dump->method('getOutput')->willReturn('migration rows');
        $dump->expects($this->once())->method('run')->with(null, $this->callback(
            static fn (array $variables): bool => ($variables['HYPERVEL_LOAD_MIGRATION_TABLE'] ?? null) === 'prefix_' . $migrationTable,
        ))->willReturn(0);
        $files = $this->createMock(Filesystem::class);
        $files->expects($this->once())->method('append')->with('/schema.sql', 'migration rows');

        $commands = [];
        $state = new MySqlSchemaState($connection, $files, static function (string $command) use (&$commands, $version, $dump): Process {
            $commands[] = $command;

            return $command === 'mysql --version' ? $version : $dump;
        });
        $state->withMigrationTable($migrationTable);

        (new ReflectionMethod($state, 'appendMigrationData'))->invoke($state, '/schema.sql');

        $this->assertStringContainsString(' "${:HYPERVEL_LOAD_MIGRATION_TABLE}" --no-create-info', $commands[1]);
    }

    public function testExecuteDumpProcessForDepth(): void
    {
        $connection = $this->createStub(MySqlConnection::class);
        $schemaState = new MySqlSchemaState($connection);

        $this->expectExceptionObject(new Exception('Dump execution exceeded maximum depth of 30.'));

        $method = new ReflectionMethod(get_class($schemaState), 'executeDumpProcess');
        $method->invoke($schemaState, new Process(['unused']), null, [], 31);
    }
}
