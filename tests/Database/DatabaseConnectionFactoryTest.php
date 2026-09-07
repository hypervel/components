<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Generator;
use Hypervel\Container\Container;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PDO;
use ReflectionProperty;
use stdClass;

class DatabaseConnectionFactoryTest extends TestCase
{
    protected $db;

    protected function setUp(): void
    {
        $this->db = new DB;

        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->addConnection([
            'url' => 'sqlite:///:memory:',
        ], 'url');

        $this->db->addConnection([
            'driver' => 'sqlite',
            'read' => [
                'database' => ':memory:',
            ],
            'write' => [
                'database' => ':memory:',
            ],
        ], 'read_write');

        $this->db->setAsGlobal();
    }

    public function testConnectionCanBeCreated()
    {
        $this->assertInstanceOf(PDO::class, $this->db->getConnection()->getPdo());
        $this->assertInstanceOf(PDO::class, $this->db->getConnection()->getReadPdo());
        $this->assertInstanceOf(PDO::class, $this->db->getConnection('read_write')->getPdo());
        $this->assertInstanceOf(PDO::class, $this->db->getConnection('read_write')->getReadPdo());
        $this->assertInstanceOf(PDO::class, $this->db->getConnection('url')->getPdo());
        $this->assertInstanceOf(PDO::class, $this->db->getConnection('url')->getReadPdo());
    }

    public function testConnectionFromUrlHasProperConfig()
    {
        $this->db->addConnection([
            'url' => 'mysql://root:pass@db/local?strict=true',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
        ], 'url-config');

        $this->assertEquals([
            'name' => 'url-config',
            'driver' => 'mysql',
            'database' => 'local',
            'host' => 'db',
            'username' => 'root',
            'password' => 'pass',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ], $this->db->getConnection('url-config')->getConfig());
    }

    public function testParseConfigParsesUrlAndAddsDefaults(): void
    {
        $factory = new ConnectionFactory(new Container);

        $config = $factory->parseConfig([
            'url' => 'mysql://root:pass@db/local?strict=true',
            'prefix_indexes' => true,
        ], 'url-config');

        $this->assertSame('url-config', $config['name']);
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('local', $config['database']);
        $this->assertSame('db', $config['host']);
        $this->assertSame('root', $config['username']);
        $this->assertSame('pass', $config['password']);
        $this->assertSame('', $config['prefix']);
        $this->assertTrue($config['strict']);
        $this->assertTrue($config['prefix_indexes']);
    }

    public function testHasReadConfigRequiresNonNullReadConfig(): void
    {
        $factory = new ConnectionFactory(new Container);

        $this->assertTrue($factory->hasReadConfig(['read' => []]));
        $this->assertFalse($factory->hasReadConfig(['read' => null]));
        $this->assertFalse($factory->hasReadConfig([]));
    }

    public function testConfigForReadDerivesSingleRoleReadConfig(): void
    {
        $factory = new ConnectionFactory(new Container);

        $config = $factory->configForRead([
            'driver' => 'mysql',
            'name' => 'mysql',
            'database' => 'app',
            'host' => 'write-host',
            'username' => 'root',
            'password' => '',
            'prefix' => 'app_',
            'read' => [
                'host' => ['read-one', 'read-two'],
                'username' => 'reader',
            ],
            'write' => [
                'host' => 'write-host',
            ],
        ]);

        $this->assertSame('mysql', $config['name']);
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('app', $config['database']);
        $this->assertSame('reader', $config['username']);
        $this->assertSame(['read-one', 'read-two'], $config['host']);
        $this->assertSame('app_', $config['prefix']);
        $this->assertSame(ConnectionName::READ, $config[Connection::READ_WRITE_TYPE_CONFIG_KEY]);
        $this->assertArrayNotHasKey('read', $config);
        $this->assertArrayNotHasKey('write', $config);
    }

    public function testConfigForReadParsesNestedReadUrl(): void
    {
        $factory = new ConnectionFactory(new Container);

        $config = $factory->configForRead([
            'driver' => 'mysql',
            'name' => 'mysql',
            'database' => 'write_database',
            'host' => 'write-host',
            'username' => 'writer',
            'password' => '',
            'read' => [
                'url' => 'mysql://reader:secret@read-host/read_database?strict=true',
            ],
            'write' => [
                'host' => 'write-host',
            ],
        ]);

        $this->assertSame('mysql', $config['name']);
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('read_database', $config['database']);
        $this->assertSame('read-host', $config['host']);
        $this->assertSame('reader', $config['username']);
        $this->assertSame('secret', $config['password']);
        $this->assertSame('', $config['prefix']);
        $this->assertTrue($config['strict']);
        $this->assertSame(ConnectionName::READ, $config[Connection::READ_WRITE_TYPE_CONFIG_KEY]);
        $this->assertArrayNotHasKey('url', $config);
        $this->assertArrayNotHasKey('read', $config);
        $this->assertArrayNotHasKey('write', $config);
    }

    public function testReadAndWriteConfigsParseTheirNestedUrls(): void
    {
        $factory = new FactoryTestConnectionFactory(new Container);
        $config = [
            'driver' => 'mysql',
            'name' => 'mysql',
            'database' => 'primary_database',
            'host' => 'primary-host',
            'username' => 'primary',
            'password' => '',
            'prefix' => 'app_',
            'read' => [
                'url' => 'mysql://reader:read-secret@read-host/read_database?strict=true',
            ],
            'write' => [
                'url' => 'mysql://writer:write-secret@write-host/write_database?charset=utf8mb4',
            ],
        ];

        $readConfig = $factory->readConfig($config);
        $writeConfig = $factory->writeConfig($config);

        $this->assertSame('read_database', $readConfig['database']);
        $this->assertSame('read-host', $readConfig['host']);
        $this->assertSame('reader', $readConfig['username']);
        $this->assertSame('read-secret', $readConfig['password']);
        $this->assertTrue($readConfig['strict']);
        $this->assertSame('write_database', $writeConfig['database']);
        $this->assertSame('write-host', $writeConfig['host']);
        $this->assertSame('writer', $writeConfig['username']);
        $this->assertSame('write-secret', $writeConfig['password']);
        $this->assertSame('utf8mb4', $writeConfig['charset']);

        foreach ([$readConfig, $writeConfig] as $endpointConfig) {
            $this->assertSame('mysql', $endpointConfig['name']);
            $this->assertSame('app_', $endpointConfig['prefix']);
            $this->assertArrayNotHasKey('url', $endpointConfig);
            $this->assertArrayNotHasKey('read', $endpointConfig);
            $this->assertArrayNotHasKey('write', $endpointConfig);
        }
    }

    public function testEndpointConfigsPreserveInheritedValuesForPartialOverrides(): void
    {
        $factory = new FactoryTestConnectionFactory(new Container);
        $config = [
            'driver' => 'mysql',
            'name' => 'mysql',
            'database' => 'primary_database',
            'host' => 'primary-host',
            'username' => 'primary',
            'password' => 'secret',
            'prefix' => 'app_',
            'read' => ['host' => 'read-host'],
            'write' => ['host' => 'write-host'],
        ];

        $readConfig = $factory->readConfig($config);
        $writeConfig = $factory->writeConfig($config);

        $this->assertSame('read-host', $readConfig['host']);
        $this->assertSame('write-host', $writeConfig['host']);

        foreach ([$readConfig, $writeConfig] as $endpointConfig) {
            $this->assertSame('primary_database', $endpointConfig['database']);
            $this->assertSame('primary', $endpointConfig['username']);
            $this->assertSame('secret', $endpointConfig['password']);
            $this->assertSame('app_', $endpointConfig['prefix']);
        }
    }

    public function testConfigForReadTreatsEmptyReadConfigAsBaseConfigWithReadRole(): void
    {
        $factory = new ConnectionFactory(new Container);

        $config = $factory->configForRead([
            'driver' => 'sqlite',
            'name' => 'default',
            'database' => 'database.sqlite',
            'read' => [],
        ]);

        $this->assertSame('default', $config['name']);
        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame('database.sqlite', $config['database']);
        $this->assertSame('', $config['prefix']);
        $this->assertSame(ConnectionName::READ, $config[Connection::READ_WRITE_TYPE_CONFIG_KEY]);
        $this->assertArrayNotHasKey('read', $config);
        $this->assertArrayNotHasKey('write', $config);
    }

    public function testSingleConnectionNotCreatedUntilNeeded()
    {
        $connection = $this->db->getConnection();
        $pdo = new ReflectionProperty(get_class($connection), 'pdo');
        $readPdo = new ReflectionProperty(get_class($connection), 'readPdo');

        $this->assertNotInstanceOf(PDO::class, $pdo->getValue($connection));
        $this->assertNotInstanceOf(PDO::class, $readPdo->getValue($connection));
    }

    public function testReadWriteConnectionsNotCreatedUntilNeeded()
    {
        $connection = $this->db->getConnection('read_write');
        $pdo = new ReflectionProperty(get_class($connection), 'pdo');
        $readPdo = new ReflectionProperty(get_class($connection), 'readPdo');

        $this->assertNotInstanceOf(PDO::class, $pdo->getValue($connection));
        $this->assertNotInstanceOf(PDO::class, $readPdo->getValue($connection));
    }

    public function testReadWriteConnectionSetsReadConnectionConfig(): void
    {
        $connection = $this->db->getConnection('read_write');

        $readConnectionConfig = new ReflectionProperty(get_class($connection), 'readConnectionConfig');

        $config = $readConnectionConfig->getValue($connection);

        $this->assertNotEmpty($config);
        $this->assertArrayHasKey('database', $config);
        $this->assertSame(':memory:', $config['database']);
    }

    public function testIfDriverIsntSetExceptionIsThrown()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A driver must be specified.');

        $factory = new ConnectionFactory($container = m::mock(Container::class));
        $factory->createConnector(['foo']);
    }

    public function testExceptionIsThrownOnUnsupportedDriver()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver [foo]');

        $factory = new ConnectionFactory($container = m::mock(Container::class));
        $container->shouldReceive('bound')->once()->andReturn(false);
        $factory->createConnector(['driver' => 'foo']);
    }

    public function testCustomConnectorsCanBeResolvedViaContainer()
    {
        $connector = m::mock(\Hypervel\Database\Connectors\ConnectorInterface::class);
        $factory = new ConnectionFactory($container = m::mock(Container::class));
        $container->shouldReceive('bound')->once()->with('db.connector.foo')->andReturn(true);
        $container->shouldReceive('make')->once()->with('db.connector.foo')->andReturn($connector);

        $this->assertSame($connector, $factory->createConnector(['driver' => 'foo']));
    }

    public function testSqliteForeignKeyConstraints()
    {
        $this->db->addConnection([
            'url' => 'sqlite:///:memory:?foreign_key_constraints=true',
        ], 'constraints_set');

        $this->assertEquals(0, $this->db->getConnection()->select('PRAGMA foreign_keys')[0]->foreign_keys);

        $this->assertEquals(1, $this->db->getConnection('constraints_set')->select('PRAGMA foreign_keys')[0]->foreign_keys);
    }

    public function testSqliteBusyTimeout()
    {
        $this->db->addConnection([
            'url' => 'sqlite:///:memory:?busy_timeout=1234',
        ], 'busy_timeout_set');

        // Can't compare to 0, default value may be something else
        $this->assertNotSame(1234, $this->db->getConnection()->select('PRAGMA busy_timeout')[0]->timeout);

        $this->assertSame(1234, $this->db->getConnection('busy_timeout_set')->select('PRAGMA busy_timeout')[0]->timeout);
    }

    public function testSqliteSynchronous()
    {
        $this->db->addConnection([
            'url' => 'sqlite:///:memory:?synchronous=NORMAL',
        ], 'synchronous_set');

        $this->assertSame(2, $this->db->getConnection()->select('PRAGMA synchronous')[0]->synchronous);

        $this->assertSame(1, $this->db->getConnection('synchronous_set')->select('PRAGMA synchronous')[0]->synchronous);
    }

    public function testExtendWithDriverName()
    {
        $factory = new ConnectionFactory(new Container);
        $custom = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

        $factory->extend('sqlite', function (array $config, ?string $name) use ($custom) {
            return $custom;
        });

        $result = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'default');

        $this->assertSame($custom, $result);
    }

    public function testExtendWithConnectionName()
    {
        $factory = new ConnectionFactory(new Container);
        $custom = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

        $factory->extend('my-connection', function (array $config, ?string $name) use ($custom) {
            return $custom;
        });

        $result = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'my-connection');

        $this->assertSame($custom, $result);
    }

    public function testDriverExtensionTakesPrecedenceOverBuiltInDrivers()
    {
        $factory = new ConnectionFactory(new Container);
        $custom = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

        $factory->extend('sqlite', function () use ($custom) {
            return $custom;
        });

        $result = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'default');

        // Should use the extension, not the built-in SQLiteConnection creation
        $this->assertSame($custom, $result);
    }

    public function testConnectionNameExtensionTakesPrecedenceOverDriverExtension()
    {
        $factory = new ConnectionFactory(new Container);
        $connectionSpecific = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
        $driverLevel = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

        $factory->extend('my-conn', function () use ($connectionSpecific) {
            return $connectionSpecific;
        });
        $factory->extend('sqlite', function () use ($driverLevel) {
            return $driverLevel;
        });

        $result = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'my-conn');

        $this->assertSame($connectionSpecific, $result);
        $this->assertNotSame($driverLevel, $result);
    }

    public function testForgetExtensionRemovesExtension()
    {
        $factory = new ConnectionFactory(new Container);
        $custom = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

        $factory->extend('sqlite', function () use ($custom) {
            return $custom;
        });

        // Verify extension is active
        $this->assertSame($custom, $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'default'));

        // Forget it
        $factory->forgetExtension('sqlite');

        // Normal creation should resume — result is a new SQLiteConnection, not our custom one
        $result = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'default');
        $this->assertNotSame($custom, $result);
        $this->assertInstanceOf(SQLiteConnection::class, $result);
    }

    public function testExtensionCallbackReceivesConfigAndName()
    {
        $factory = new ConnectionFactory(new Container);
        $receivedConfig = null;
        $receivedName = null;

        $factory->extend('sqlite', function (array $config, ?string $name) use (&$receivedConfig, &$receivedName) {
            $receivedConfig = $config;
            $receivedName = $name;

            return new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
        });

        $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'my-conn');

        $this->assertSame('my-conn', $receivedName);
        $this->assertSame('sqlite', $receivedConfig['driver']);
        $this->assertSame(':memory:', $receivedConfig['database']);
        // parseConfig adds prefix and name
        $this->assertArrayHasKey('prefix', $receivedConfig);
        $this->assertSame('my-conn', $receivedConfig['name']);
    }

    public function testConfigFirstExtensionCreatesNeutralConnectionWithoutResolvingAConnector(): void
    {
        $container = m::mock(Container::class);
        $factory = new ConnectionFactory($container);
        $receivedConfig = null;

        $factory->extend('http', function (array $config, ?string $name) use (&$receivedConfig): FactoryNonPdoConnection {
            $receivedConfig = $config;

            return new FactoryNonPdoConnection(
                $config['database'] ?? '',
                $config['prefix'],
                $config,
            );
        });

        $result = $factory->make([
            'driver' => 'http',
            'endpoint' => 'https://database.test',
        ], 'analytics');

        $this->assertInstanceOf(FactoryNonPdoConnection::class, $result);
        $this->assertSame('analytics', $result->getName());
        $this->assertSame('https://database.test', $result->getConfig('endpoint'));
        $this->assertSame('analytics', $receivedConfig['name']);
        $this->assertSame('http', $receivedConfig['driver']);
        $this->assertSame('https://database.test', $receivedConfig['endpoint']);
        $this->assertSame('', $receivedConfig['prefix']);
    }

    public function testConnectionExtensionMustReturnANeutralConnection(): void
    {
        $factory = new ConnectionFactory(new Container);
        $factory->extend('http', static fn (): object => new stdClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database connection extensions must return a Connection instance.');

        $factory->make(['driver' => 'http'], 'analytics');
    }

    public function testSharedInMemorySqliteUsesTheSamePdoSubclassAndWriteConfigForEveryGeneration(): void
    {
        $factory = new ConnectionFactory(new Container);
        $resolvedConfigs = [];
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) use (&$resolvedConfigs) {
            $resolvedConfigs[] = $config;

            return new FactorySqliteConnection($connection, $database, $prefix, $config);
        });
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => 'ignored.sqlite'],
            'write' => ['database' => ':memory:'],
        ];

        try {
            $initial = $factory->makeSharedInMemorySqliteConnection($config, 'memory');
            $pdo = $initial->getPdo();
            $replacement = $factory->makeSqliteFromSharedPdo($pdo, $config, 'memory');

            $this->assertInstanceOf(FactorySqliteConnection::class, $initial);
            $this->assertInstanceOf(FactorySqliteConnection::class, $replacement);
            $this->assertSame($pdo, $replacement->getPdo());
            $this->assertCount(2, $resolvedConfigs);
            $this->assertSame($resolvedConfigs[0], $resolvedConfigs[1]);
            $this->assertArrayNotHasKey('read', $resolvedConfigs[0]);
            $this->assertArrayNotHasKey('write', $resolvedConfigs[0]);

            $initial->refreshFrom($replacement);

            $this->assertSame($pdo, $initial->getPdo());
        } finally {
            Connection::flushState();
        }
    }

    public function testSharedInMemorySqliteRejectsConfigFirstExtensionsBeforeConnectionCreation(): void
    {
        $factory = new ConnectionFactory(new Container);
        $factory->extend('sqlite', static fn (): FactoryNonPdoConnection => new FactoryNonPdoConnection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "Pooled in-memory SQLite connections cannot use config-first extensions. Use Connection::resolverFor('sqlite', ...) to register a PDO connection subclass."
        );

        $factory->makeSharedInMemorySqliteConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'memory');
    }

    public function testSharedInMemorySqliteRejectsNameSpecificExtensionsBeforeConnectionCreation(): void
    {
        $factory = new ConnectionFactory(new Container);
        $factory->extend('memory', static fn (): FactoryNonPdoConnection => new FactoryNonPdoConnection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "Pooled in-memory SQLite connections cannot use config-first extensions. Use Connection::resolverFor('sqlite', ...) to register a PDO connection subclass."
        );

        $factory->makeSharedInMemorySqliteConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'memory');
    }

    public function testSqlitePdoResolverMustReturnAPdoConnection(): void
    {
        $factory = new ConnectionFactory(new Container);
        Connection::resolverFor('sqlite', static fn (): FactoryNonPdoConnection => new FactoryNonPdoConnection);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('PDO connection resolvers must return a PdoConnection instance.');

            $factory->makeSharedInMemorySqliteConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ], 'memory');
        } finally {
            Connection::flushState();
        }
    }
}

class FactorySqliteConnection extends SQLiteConnection
{
}

class FactoryTestConnectionFactory extends ConnectionFactory
{
    /**
     * Get the read connection configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function readConfig(array $config): array
    {
        return $this->getReadConfig($config);
    }

    /**
     * Get the write connection configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function writeConfig(array $config): array
    {
        return $this->getWriteConfig($config);
    }
}

class FactoryNonPdoConnection extends Connection
{
    protected bool $driverResourcesPresent = true;

    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return [];
    }

    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from [];
    }

    public function statement(string $query, array $bindings = []): bool
    {
        return true;
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        return 0;
    }

    public function unprepared(string $query): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return $this->driverResourcesPresent;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function getServerVersion(): string
    {
        return '1.0';
    }

    protected function getDefaultDriverName(): string
    {
        return 'http';
    }

    protected function escapeString(string $value): string
    {
        return "'{$value}'";
    }

    protected function hasDriverResources(): bool
    {
        return $this->driverResourcesPresent;
    }

    protected function disconnectDriverResources(): void
    {
        $this->forgetDriverResources();
    }

    protected function forgetDriverResources(): void
    {
        $this->driverResourcesPresent = false;
    }

    protected function replaceDriverResources(Connection $fresh): void
    {
        /** @var self $fresh */
        $driverResourcesPresent = $fresh->driverResourcesPresent;

        try {
            $this->disconnectDriverResources();
        } finally {
            $this->driverResourcesPresent = $driverResourcesPresent;
        }
    }
}
