<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Closure;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Foundation\Testing\Concerns\InteractsWithParallelDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

class InteractsWithParallelDatabaseTest extends TestCase
{
    use InteractsWithParallelDatabase;

    public function testParallelTestDatabaseAppendsTokenToName(): void
    {
        $result = $this->parallelTestDatabase('testing', '3');

        $this->assertSame('testing_test_3', $result);
    }

    public function testParallelTestDatabaseStripsOnlyTheCurrentTokenSuffix(): void
    {
        $first = $this->parallelTestDatabase('analytics_test_data', '1');
        $second = $this->parallelTestDatabase('analytics_test_data_test_1', '1');

        $this->assertSame('analytics_test_data_test_1', $first);
        $this->assertSame('analytics_test_data_test_1', $second);
    }

    #[DataProvider('databaseUrlConfigurations')]
    public function testConfigureParallelDatabaseNameNormalizesDatabaseUrls(
        array $configuration,
        array $expected
    ): void {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $configurationPath = "database.connections.{$connection}";
        $config->set($configurationPath, $configuration);

        $this->withParallelEnvironment('7', false, function () use ($config, $configurationPath, $expected): void {
            $this->configureParallelDatabaseName($this->app);

            $normalized = $config->get($configurationPath);

            foreach ($expected as $key => $value) {
                $this->assertSame($value, $normalized[$key]);
            }

            $this->assertArrayNotHasKey('url', $normalized);
        });
    }

    public static function databaseUrlConfigurations(): iterable
    {
        yield 'MySQL URL fields and query options override discrete fields' => [
            [
                'url' => 'mysql://reader%3A:secret%2F@url-host:3307/url_database?charset=utf8mb4&options=foo%2Fbar',
                'driver' => 'pgsql',
                'host' => 'discrete-host',
                'port' => 5432,
                'database' => 'discrete_database',
                'username' => 'discrete-user',
                'password' => 'discrete-password',
                'prefix' => 'app_',
                'charset' => 'latin1',
            ],
            [
                'driver' => 'mysql',
                'host' => 'url-host',
                'port' => 3307,
                'database' => 'url_database_test_7',
                'username' => 'reader:',
                'password' => 'secret/',
                'prefix' => 'app_',
                'charset' => 'utf8mb4',
                'options' => 'foo/bar',
            ],
        ];

        yield 'host-only PostgreSQL URL preserves the discrete database' => [
            [
                'url' => 'postgresql://worker:secret@postgres-host:5432',
                'database' => 'separate_database',
                'prefix' => 'app_',
            ],
            [
                'driver' => 'pgsql',
                'host' => 'postgres-host',
                'port' => 5432,
                'database' => 'separate_database_test_7',
                'username' => 'worker',
                'password' => 'secret',
                'prefix' => 'app_',
            ],
        ];

        yield 'relative SQLite URL' => [
            ['url' => 'sqlite:///storage/test-database.sqlite'],
            [
                'driver' => 'sqlite',
                'database' => 'storage/test-database.sqlite_test_7',
            ],
        ];

        yield 'absolute SQLite URL' => [
            ['url' => 'sqlite:////tmp/test-database.sqlite'],
            [
                'driver' => 'sqlite',
                'database' => '/tmp/test-database.sqlite_test_7',
            ],
        ];
    }

    public function testConfigureParallelDatabaseNameIsNoOpWithoutTestToken(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $original = $config->get("database.connections.{$connection}.database");

        $this->configureParallelDatabaseName($this->app);

        $this->assertSame($original, $config->get("database.connections.{$connection}.database"));
    }

    public function testConfigureParallelDatabaseNameSkipsInMemorySqlite(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", ':memory:');

        $this->configureParallelDatabaseName($this->app);

        $this->assertSame(':memory:', $config->get("database.connections.{$connection}.database"));
    }

    public function testConfigureParallelDatabaseNameSkipsSqliteMemoryUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file::memory:');

        $this->withParallelEnvironment('7', false, function () use ($config, $connection): void {
            $this->configureParallelDatabaseName($this->app);

            $this->assertSame(
                'file::memory:',
                $config->get("database.connections.{$connection}.database")
            );
        });
    }

    public function testConfigureParallelDatabaseNameSkipsSqliteMemoryUrl(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $configurationPath = "database.connections.{$connection}";
        $configuration = ['url' => 'sqlite:///:memory:'];
        $config->set($configurationPath, $configuration);

        $this->withParallelEnvironment('7', false, function () use ($config, $configurationPath, $configuration): void {
            $this->configureParallelDatabaseName($this->app);

            $this->assertSame($configuration, $config->get($configurationPath));
        });
    }

    public function testConfigureParallelDatabaseNameRejectsSqliteFileUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file:/tmp/database.sqlite?mode=rwc');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SQLite URI databases cannot be automatically managed during parallel testing. '
            . 'Configure a plain filesystem path or run with --without-databases.'
        );

        $this->withParallelEnvironment(
            '7',
            false,
            fn () => $this->configureParallelDatabaseName($this->app)
        );
    }

    public function testConfigureParallelDatabaseNameHonorsWithoutDatabasesOption(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $database = $config->get("database.connections.{$connection}.database");

        $this->withParallelEnvironment('7', true, function () use ($config, $connection, $database): void {
            $this->configureParallelDatabaseName($this->app);

            $this->assertSame(
                $database,
                $config->get("database.connections.{$connection}.database")
            );
        });
    }

    public function testConfigureParallelDatabaseNameAcceptsInheritedReadWriteDatabaseIdentity(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $configurationPath = "database.connections.{$connection}";
        $config->set($configurationPath, [
            'driver' => 'mysql',
            'database' => 'testing',
            'read' => ['host' => ['read-one', 'read-two']],
            'write' => [
                ['host' => 'write-one'],
                ['host' => 'write-two'],
            ],
        ]);

        $this->withParallelEnvironment('7', false, function () use ($config, $configurationPath): void {
            $this->configureParallelDatabaseName($this->app);

            $this->assertSame('testing_test_7', $config->get("{$configurationPath}.database"));
        });
    }

    #[DataProvider('unsupportedReadWriteConfigurations')]
    public function testConfigureParallelDatabaseNameRejectsEndpointSpecificDatabaseIdentity(
        array $configuration
    ): void {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}", $configuration);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Read/write connections with endpoint-specific databases or URLs cannot be automatically managed during parallel testing. '
            . 'Configure a single database identity or run with --without-databases.'
        );

        $this->withParallelEnvironment(
            '7',
            false,
            fn () => $this->configureParallelDatabaseName($this->app)
        );
    }

    #[DataProvider('unsupportedReadWriteConfigurations')]
    public function testEnsureParallelDatabaseExistsRejectsEndpointSpecificDatabaseIdentity(
        array $configuration
    ): void {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}", $configuration);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Read/write connections with endpoint-specific databases or URLs cannot be automatically managed during parallel testing. '
            . 'Configure a single database identity or run with --without-databases.'
        );

        $this->withParallelEnvironment(
            '7',
            false,
            fn () => $this->ensureParallelDatabaseExists()
        );
    }

    public static function unsupportedReadWriteConfigurations(): iterable
    {
        yield 'associative read database' => [[
            'driver' => 'mysql',
            'database' => 'testing',
            'read' => ['database' => 'reader'],
        ]];

        yield 'associative write URL' => [[
            'driver' => 'mysql',
            'database' => 'testing',
            'write' => ['url' => 'mysql://writer:secret@write-host/writer'],
        ]];

        yield 'list read database' => [[
            'driver' => 'mysql',
            'database' => 'testing',
            'read' => [
                ['host' => 'read-one'],
                ['database' => 'reader'],
            ],
        ]];

        yield 'query-derived read database' => [[
            'url' => 'mysql://worker:secret@host/testing?read[database]=reader',
        ]];
    }

    public function testConfigureParallelDatabaseNameSkipsEmptyDatabase(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", '');

        $this->configureParallelDatabaseName($this->app);

        $this->assertSame('', $config->get("database.connections.{$connection}.database"));
    }

    public function testConfigureParallelDatabaseNameSkipsUnconfiguredConnection(): void
    {
        $config = $this->app->make('config');
        $config->set('database.default', 'nonexistent');

        // Should not throw — just skip
        $this->configureParallelDatabaseName($this->app);
    }

    public function testEnsureParallelDatabaseExistsIsNoOpWithoutTestToken(): void
    {
        // Without TEST_TOKEN, should be a no-op (no exceptions)
        $this->ensureParallelDatabaseExists();

        $this->assertTrue(true);
    }

    public function testEnsureParallelDatabaseExistsSkipsLateConfiguredSqliteMemoryUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file::memory:');

        $this->withParallelEnvironment('7', false, function (): void {
            $this->ensureParallelDatabaseExists();

            $this->assertTrue(true);
        });
    }

    public function testEnsureParallelDatabaseExistsRejectsLateConfiguredSqliteFileUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file:/tmp/database.sqlite?mode=rwc');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SQLite URI databases cannot be automatically managed during parallel testing. '
            . 'Configure a plain filesystem path or run with --without-databases.'
        );

        $this->withParallelEnvironment(
            '7',
            false,
            fn () => $this->ensureParallelDatabaseExists()
        );
    }

    public function testEnsureParallelDatabaseExistsHonorsWithoutDatabasesOption(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file:/tmp/database.sqlite?mode=rwc');

        $this->withParallelEnvironment('7', true, function (): void {
            $this->ensureParallelDatabaseExists();

            $this->assertTrue(true);
        });
    }

    public function testEnsureParallelDatabaseExistsRecoversFromTheCurrentSuffixedDatabase(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $configurationPath = "database.connections.{$connection}";
        $config->set($configurationPath, [
            'driver' => 'mysql',
            'database' => 'analytics_test_data_test_7',
        ]);

        $queryException = new QueryException(
            $connection,
            'select 1',
            [],
            new PDOException('Database does not exist.')
        );
        $schemaBuilder = m::mock(SchemaBuilder::class);
        $schemaBuilder->shouldReceive('hasTable')
            ->once()
            ->with('__parallel_check')
            ->andThrow($queryException);
        $schemaBuilder->shouldReceive('createDatabase')
            ->once()
            ->with('analytics_test_data_test_7')
            ->andReturn(true);
        $databaseConnection = m::mock(ConnectionInterface::class);
        $databaseConnection->shouldReceive('getSchemaBuilder')
            ->twice()
            ->andReturn($schemaBuilder);
        $connectionCalls = 0;
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('connection')
            ->twice()
            ->with($connection)
            ->andReturnUsing(function () use (
                &$connectionCalls,
                $config,
                $configurationPath,
                $databaseConnection
            ): ConnectionInterface {
                ++$connectionCalls;

                if ($connectionCalls === 2) {
                    $this->assertSame(
                        'analytics_test_data',
                        $config->get("{$configurationPath}.database")
                    );
                }

                return $databaseConnection;
            });
        $databaseManager->shouldReceive('purge')->twice()->with($connection);
        $this->app->instance('db', $databaseManager);
        DB::clearResolvedInstance();

        $this->withParallelEnvironment('7', false, fn () => $this->ensureParallelDatabaseExists());

        $this->assertSame(
            'analytics_test_data_test_7',
            $config->get("{$configurationPath}.database")
        );
    }

    /**
     * Run a callback with an isolated parallel-testing environment.
     */
    private function withParallelEnvironment(
        string $token,
        bool $withoutDatabases,
        Closure $callback
    ): void {
        $previousProcessToken = getenv('TEST_TOKEN');
        $previousServerTokenExists = array_key_exists('TEST_TOKEN', $_SERVER);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentTokenExists = array_key_exists('TEST_TOKEN', $_ENV);
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousWithoutDatabasesExists = array_key_exists(
            'HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES',
            $_SERVER
        );
        $previousWithoutDatabases = $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] ?? null;

        putenv("TEST_TOKEN={$token}");
        $_SERVER['TEST_TOKEN'] = $token;
        $_ENV['TEST_TOKEN'] = $token;

        if ($withoutDatabases) {
            $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = 1;
        } else {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
        }

        try {
            $callback();
        } finally {
            $previousProcessToken === false
                ? putenv('TEST_TOKEN')
                : putenv("TEST_TOKEN={$previousProcessToken}");

            if ($previousServerTokenExists) {
                $_SERVER['TEST_TOKEN'] = $previousServerToken;
            } else {
                unset($_SERVER['TEST_TOKEN']);
            }

            if ($previousEnvironmentTokenExists) {
                $_ENV['TEST_TOKEN'] = $previousEnvironmentToken;
            } else {
                unset($_ENV['TEST_TOKEN']);
            }

            if ($previousWithoutDatabasesExists) {
                $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = $previousWithoutDatabases;
            } else {
                unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
            }
        }
    }
}
