<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Exception;
use Generator;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\Schema\MySqlSchemaState;
use Hypervel\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Process\Process;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMySqlSchemaStateTest extends TestCase
{
    #[DataProvider('provider')]
    public function testConnectionString(string $expectedConnectionString, array $expectedVariables, array $dbConfig): void
    {
        $connection = $this->createMock(MySqlConnection::class);
        $connection->method('getConfig')->willReturn($dbConfig);

        $schemaState = new MySqlSchemaState($connection);

        $versionInfo = ['version' => '8.0.0', 'isMariaDb' => false];

        // test connectionString
        $method = new ReflectionMethod(get_class($schemaState), 'connectionString');
        $connString = $method->invoke($schemaState, $versionInfo);

        self::assertEquals($expectedConnectionString, $connString);

        // test baseVariables
        $method = new ReflectionMethod(get_class($schemaState), 'baseVariables');
        $variables = $method->invoke($schemaState, $dbConfig);

        self::assertEquals($expectedVariables, $variables);
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

        yield 'ssl_ca' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --ssl-ca="${:HYPERVEL_LOAD_SSL_CA}"', [
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
                    PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA => 'ssl.ca',
                ],
            ],
        ];

        yield 'ssl_cert_and_key' => [
            ' --user="${:HYPERVEL_LOAD_USER}" --password="${:HYPERVEL_LOAD_PASSWORD}" --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --ssl-ca="${:HYPERVEL_LOAD_SSL_CA}" --ssl-cert="${:HYPERVEL_LOAD_SSL_CERT}" --ssl-key="${:HYPERVEL_LOAD_SSL_KEY}"', [
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
                    PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA => 'ssl.ca',
                    PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CERT : PDO::MYSQL_ATTR_SSL_CERT => '/path/to/client-cert.pem',
                    PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_KEY : PDO::MYSQL_ATTR_SSL_KEY => '/path/to/client-key.pem',
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
                    PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
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

    public function testExecuteDumpProcessForDepth()
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('setTimeout')->willReturnSelf();
        $mockProcess->method('mustRun')->will(
            $this->throwException(new Exception('column-statistics'))
        );

        $mockOutput = null;
        $mockVariables = [];

        $schemaState = $this->getMockBuilder(MySqlSchemaState::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['makeProcess'])
            ->getMock();

        $schemaState->method('makeProcess')->willReturn($mockProcess);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Dump execution exceeded maximum depth of 30.');

        // test executeDumpProcess
        $method = new ReflectionMethod(get_class($schemaState), 'executeDumpProcess');
        $method->invoke($schemaState, $mockProcess, $mockOutput, $mockVariables, 31);
    }
}
