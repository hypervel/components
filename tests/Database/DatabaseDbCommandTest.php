<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Console\DbCommand;
use Hypervel\Database\DatabaseCliManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UnexpectedValueException;

class DatabaseDbCommandTest extends TestCase
{
    public function testReadOptionMergesFirstListConfigAndStripsReadWriteConfig(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [
                    ['host' => 'read-one', 'username' => 'reader-one'],
                    ['host' => 'read-two', 'username' => 'reader-two'],
                ],
                'write' => [
                    ['host' => 'write-one'],
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('read-one', $connection['host']);
        $this->assertSame('reader-one', $connection['username']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testWriteOptionMergesFirstListConfigAndStripsReadWriteConfig(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [
                    ['host' => 'read-one'],
                ],
                'write' => [
                    ['host' => 'write-one', 'username' => 'writer-one'],
                    ['host' => 'write-two', 'username' => 'writer-two'],
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--write' => true,
        ]);

        $this->assertSame('write-one', $connection['host']);
        $this->assertSame('writer-one', $connection['username']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testReadOptionUsesFirstHostFromHostArray(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [
                    'host' => ['read-one', 'read-two'],
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('read-one', $connection['host']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testEmptyReadConfigReturnsBaseConfigWithoutReadWriteConfig(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [],
                'write' => [
                    'host' => 'write-one',
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('write-host', $connection['host']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testDefaultConnectionIsReadFromConfigRepository(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig(),
        ]);

        $this->assertSame('write-host', $connection['host']);
    }

    #[DataProvider('hostConfigurations')]
    public function testHostListsAreNormalizedAfterRoleSelection(array $configuration, array $input, ?string $expected): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig($configuration),
        ], $input);

        $this->assertSame($expected, $connection['host']);
    }

    /**
     * Provide base and role host lists, including empty effective lists.
     */
    public static function hostConfigurations(): array
    {
        return [
            'base hosts' => [['host' => ['first', 'second']], [], 'first'],
            'empty read override' => [['host' => ['first', 'second'], 'read' => []], ['--read' => true], 'first'],
            'empty base hosts' => [['host' => []], [], null],
            'empty role hosts' => [['read' => ['host' => []]], ['--read' => true], null],
            'empty inherited hosts' => [['host' => [], 'read' => ['username' => 'reader']], ['--read' => true], null],
        ];
    }

    public function testUnknownConnectionUsesTheCommandSpecificError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid database connection [missing].');

        $this->getConnection([], ['connection' => 'missing']);
    }

    public function testUrlConfigIsParsedBeforeReadWriteMerge(): void
    {
        $connection = $this->getConnection([
            'mysql' => [
                'url' => 'mysql://root:secret@write-host/app?strict=true',
                'read' => [
                    'host' => ['read-one', 'read-two'],
                ],
            ],
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('mysql', $connection['driver']);
        $this->assertSame('app', $connection['database']);
        $this->assertSame('read-one', $connection['host']);
        $this->assertSame('root', $connection['username']);
        $this->assertSame('secret', $connection['password']);
        $this->assertTrue($connection['strict']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    #[DataProvider('roleUrls')]
    public function testRoleUrlOverridesBaseUrlAndRoleFields(
        string $role,
        array $override,
        string $password = 'role-secret',
    ): void {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'url' => 'mysql://base-user:base-secret@base-host:3306/base_database?charset=latin1',
                $role => $override,
            ]),
        ], ['--' . $role => true]);

        $this->assertSame('mysql', $connection['driver']);
        $this->assertSame('role-host', $connection['host']);
        $this->assertSame(3307, $connection['port']);
        $this->assertSame('role_database', $connection['database']);
        $this->assertSame('role-user', $connection['username']);
        $this->assertSame($password, $connection['password']);
        $this->assertSame('utf8mb4', $connection['charset']);
        $this->assertArrayNotHasKey('url', $connection);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    /**
     * Provide associative and list-valued role overrides with their own URLs.
     */
    public static function roleUrls(): array
    {
        $override = [
            'url' => 'mysql://role-user:role-secret@role-host:3307/role_database?charset=utf8mb4',
            'host' => 'record-host',
            'port' => 3308,
            'database' => 'record_database',
            'username' => 'record-user',
            'password' => 'record-secret',
            'charset' => 'ascii',
        ];

        return [
            'associative read' => ['read', $override],
            'first write record' => ['write', [$override, ['url' => 'mysql://ignored:invalid/app']]],
            'literal numeric password' => [
                'read',
                array_replace($override, [
                    'url' => 'mysql://role-user:123456@role-host:3307/role_database?charset=utf8mb4',
                ]),
                '123456',
            ],
        ];
    }

    public function testPartialRoleUrlPreservesInheritedValues(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'url' => 'mysql://base-user:base-secret@base-host:3306/base_database?charset=utf8mb4',
                'read' => ['url' => 'mysql://read-host'],
            ]),
        ], ['--read' => true]);

        $this->assertSame('read-host', $connection['host']);
        $this->assertSame(3306, $connection['port']);
        $this->assertSame('base_database', $connection['database']);
        $this->assertSame('base-user', $connection['username']);
        $this->assertSame('base-secret', $connection['password']);
        $this->assertSame('utf8mb4', $connection['charset']);
        $this->assertArrayNotHasKey('url', $connection);
    }

    public function testRoleUrlHostListUsesTheFirstHost(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => ['url' => 'mysql://url-host/app?host[]=read-one&host[]=read-two'],
            ]),
        ], ['--read' => true]);

        $this->assertSame('read-one', $connection['host']);
    }

    public function testMalformedSelectedRoleUrlFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The database configuration URL is malformed.');

        $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => ['url' => 'mysql://read-host:invalid/app'],
            ]),
        ], ['--read' => true]);
    }

    public function testUnselectedRoleUrlIsNotParsed(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'write' => ['url' => 'mysql://write-host:invalid/app'],
            ]),
        ], ['--read' => true]);

        $this->assertSame('read-host', $connection['host']);
        $this->assertArrayNotHasKey('write', $connection);
    }

    #[DataProvider('mysqlDrivers')]
    public function testMysqlClientsUseTheExistingArguments(string $driver): void
    {
        $command = new DbCommand;
        $connection = $this->mysqlConfig(['driver' => $driver]);

        $this->assertSame($driver, $command->getCommand($connection));
        $this->assertSame([
            '--host=write-host', '--port=3306', '--user=root', 'app',
        ], $command->commandArguments($connection));
        $this->assertNull($command->commandEnvironment($connection));
    }

    #[DataProvider('mysqlDrivers')]
    public function testMysqlClientsIncludeConfiguredOptionalArguments(string $driver): void
    {
        $command = new DbCommand;
        $connection = $this->mysqlConfig([
            'driver' => $driver,
            'password' => 'secret',
            'unix_socket' => '/tmp/mysql.sock',
            'charset' => 'utf8mb4',
        ]);

        $this->assertSame([
            '--host=write-host', '--port=3306', '--user=root',
            '--password=secret', '--socket=/tmp/mysql.sock',
            '--default-character-set=utf8mb4', 'app',
        ], $command->commandArguments($connection));
    }

    #[DataProvider('mysqlDrivers')]
    public function testMysqlClientsPreserveZeroPassword(string $driver): void
    {
        $connection = $this->mysqlConfig(['driver' => $driver, 'password' => '0']);

        $this->assertSame([
            '--host=write-host', '--port=3306', '--user=root', '--password=0', 'app',
        ], (new DbCommand)->commandArguments($connection));
    }

    /**
     * Provide the drivers that share MySQL client argument formatting.
     */
    public static function mysqlDrivers(): array
    {
        return [['mysql'], ['mariadb']];
    }

    public function testPostgresUsesEnvironmentVariablesWithoutChangingArguments(): void
    {
        $command = new DbCommand;
        $connection = $this->mysqlConfig([
            'driver' => 'pgsql', 'port' => 5432, 'password' => 'secret',
        ]);

        $this->assertSame('psql', $command->getCommand($connection));
        $this->assertSame(['app'], $command->commandArguments($connection));
        $this->assertSame([
            'PGUSER' => 'root',
            'PGHOST' => 'write-host',
            'PGPORT' => 5432,
            'PGPASSWORD' => 'secret',
        ], $command->commandEnvironment($connection));
        $this->assertSame(['app'], $command->commandArguments($connection));
    }

    public function testPostgresPreservesZeroCredentials(): void
    {
        $connection = $this->mysqlConfig([
            'driver' => 'pgsql', 'port' => 5432, 'username' => '0', 'password' => '0',
        ]);

        $this->assertSame([
            'PGUSER' => '0',
            'PGHOST' => 'write-host',
            'PGPORT' => 5432,
            'PGPASSWORD' => '0',
        ], (new DbCommand)->commandEnvironment($connection));
    }

    public function testPostgresOmitsEmptyEnvironmentVariables(): void
    {
        $command = new DbCommand;
        $connection = $this->mysqlConfig([
            'driver' => 'pgsql', 'host' => '', 'port' => null, 'username' => '',
        ]);

        $this->assertSame([], $command->commandEnvironment($connection));
    }

    public function testSqliteConfigurationDoesNotRequireAHost(): void
    {
        $command = new DbCommand;
        $connection = ['driver' => 'sqlite', 'database' => '/tmp/app database.sqlite'];

        $this->assertSame('sqlite3', $command->getCommand($connection));
        $this->assertSame(['/tmp/app database.sqlite'], $command->commandArguments($connection));
        $this->assertNull($command->commandEnvironment($connection));
    }

    public function testUnknownDriverHasATargetedExtensionError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported database CLI driver [custom]. Register a resolver using DatabaseCliManager::extend().');

        (new DbCommand)->getCommand(['driver' => 'custom']);
    }

    public function testMissingBuiltInHostReturnsFailureWithTheExistingGuidance(): void
    {
        $command = new DbCommand;
        $application = $this->applicationWithConfig([
            'mysql' => $this->mysqlConfig(['host' => []]),
        ]);
        $application->shouldReceive('make')->once()->with(DatabaseCliManager::class)->andReturn(new DatabaseCliManager);
        $command->setHypervel($application);

        $input = $this->inputFor($command, []);
        $output = new BufferedOutput;
        $style = new OutputStyle($input, $output);
        $command->setInput($input);
        $command->setOutput($style);
        (new ReflectionProperty($command, 'components'))->setValue($command, new Factory($style));

        $this->assertSame(DbCommand::FAILURE, $command->handle());
        $this->assertStringContainsString('No host specified for this database connection.', $output->fetch());
    }

    /**
     * Resolve a connection through the command's normal configuration path.
     */
    private function getConnection(array $connections, array $input = []): array
    {
        $command = new DbCommand;
        $command->setHypervel($this->applicationWithConfig($connections));
        $command->setInput($this->inputFor($command, $input));

        return $command->getConnection();
    }

    /**
     * Create an application with the supplied connection configuration.
     */
    private function applicationWithConfig(array $connections): Application&m\MockInterface
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('make')
            ->once()
            ->with('config')
            ->andReturn(new Repository([
                'database' => [
                    'default' => 'mysql',
                    'connections' => $connections,
                ],
            ]));

        return $application;
    }

    /**
     * Bind input to the database command's definition.
     */
    private function inputFor(DbCommand $command, array $input): InputInterface
    {
        $arrayInput = new ArrayInput($input);
        $arrayInput->bind($command->getDefinition());

        return $arrayInput;
    }

    /**
     * Build a MySQL connection configuration.
     */
    private function mysqlConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'host' => 'write-host',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
            'password' => '',
            'read' => [
                'host' => 'read-host',
            ],
            'write' => [
                'host' => 'write-host',
            ],
        ], $overrides);
    }
}
