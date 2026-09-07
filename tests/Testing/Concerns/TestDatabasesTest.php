<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Database\DatabaseManager;
use Hypervel\Support\Facades\Facade;
use Hypervel\Testing\Concerns\TestDatabases;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class TestDatabasesTest extends TestCase
{
    private mixed $originalParallelTesting;

    protected function setUp(): void
    {
        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;

        parent::setUp();

        Container::setInstance($container = new Container);
        Facade::setFacadeApplication($container);

        $container->instance('config', new Config([
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'database' => 'my_database',
                    ],
                ],
            ],
        ]));

        $container->singleton(ParallelTesting::class, fn ($app) => new ParallelTesting($app));

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        parent::tearDown();
    }

    public function testSwitchToDatabaseWithoutUrl(): void
    {
        $container = Container::getInstance();

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('purge')->once();
        $container->instance('db', $db);

        $config = $container->make('config');

        $this->switchToDatabase('my_database_test_1');

        $this->assertSame(
            'my_database_test_1',
            $config->get('database.connections.mysql.database')
        );
    }

    #[DataProvider('databaseUrls')]
    public function testSwitchToDatabaseWithUrl(
        string $testDatabase,
        array $configuration,
        array $expected
    ): void {
        $container = Container::getInstance();

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('purge')->once();
        $container->instance('db', $db);

        $config = $container->make('config');
        $config->set('database.connections.mysql', $configuration);

        $this->switchToDatabase($testDatabase);

        $normalized = $config->get('database.connections.mysql');

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $normalized[$key]);
        }

        $this->assertSame($testDatabase, $normalized['database']);
        $this->assertArrayNotHasKey('url', $normalized);
    }

    public static function databaseUrls(): iterable
    {
        yield 'MySQL URL' => [
            'my_database_test_1',
            [
                'url' => 'mysql://root:@127.0.0.1/my_database?charset=utf8mb4&options=foo%2Fbar',
                'prefix' => 'app_',
            ],
            [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'options' => 'foo/bar',
                'prefix' => 'app_',
            ],
        ];

        yield 'PostgreSQL URL' => [
            'my-database_test_1',
            [
                'url' => 'postgresql://my_database_user:@127.0.0.1/my-database?charset=utf8',
            ],
            [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'username' => 'my_database_user',
                'password' => '',
                'charset' => 'utf8',
            ],
        ];
    }

    public function testDatabaseNameDoesNotReuseCustomNameFromPreviousCall(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '1');

        $this->assertSame('custom_database_test_1', $this->testDatabase('custom_database'));
        $this->assertSame('my_database_test_1', $this->testDatabase('my_database'));
    }

    public function testDatabaseNameDoesNotDoubleAppendToken(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '1');

        $this->assertSame('my_database_test_1', $this->testDatabase('my_database_test_1'));
    }

    public function testSqliteMemoryUriIsNotManaged(): void
    {
        $callbackCalled = false;

        $this->whenNotUsingInMemoryDatabase(
            'sqlite',
            'file::memory:',
            false,
            function () use (&$callbackCalled): void {
                $callbackCalled = true;
            }
        );

        $this->assertFalse($callbackCalled);
    }

    public function testSqliteFileUriIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SQLite URI databases cannot be automatically managed during parallel testing. '
            . 'Configure a plain filesystem path or run with --without-databases.'
        );

        $this->whenNotUsingInMemoryDatabase(
            'sqlite',
            'file:/tmp/database.sqlite?mode=rwc',
            false,
            static function (): void {
            }
        );
    }

    public function testSqliteFileUriIsIgnoredWhenDatabaseManagementIsDisabled(): void
    {
        $callbackCalled = false;

        $this->whenNotUsingInMemoryDatabase(
            'sqlite',
            'file:/tmp/database.sqlite?mode=rwc',
            true,
            function () use (&$callbackCalled): void {
                $callbackCalled = true;
            },
            [],
            false,
        );

        $this->assertFalse($callbackCalled);
    }

    public function testInheritedReadWriteDatabaseIdentityIsManaged(): void
    {
        $callbackDatabase = null;

        $this->whenNotUsingInMemoryDatabase(
            'mysql',
            'testing',
            false,
            function (string $database) use (&$callbackDatabase): void {
                $callbackDatabase = $database;
            },
            [
                'read' => ['host' => ['read-one', 'read-two']],
                'write' => [
                    ['host' => 'write-one'],
                    ['host' => 'write-two'],
                ],
            ],
        );

        $this->assertSame('testing', $callbackDatabase);
    }

    public function testInMemorySqliteWithEndpointDatabaseIsRejectedBeforeConnectionResolution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Read/write connections with endpoint-specific databases or URLs cannot be automatically managed during parallel testing. '
            . 'Configure a single database identity or run with --without-databases.'
        );

        $this->whenNotUsingInMemoryDatabase(
            'sqlite',
            ':memory:',
            false,
            static function (): void {
            },
            ['read' => ['database' => 'persistent.sqlite']],
            false,
        );
    }

    #[DataProvider('unsupportedReadWriteConfigurations')]
    public function testEndpointSpecificDatabaseIdentityIsRejectedBeforeConnectionResolution(
        array $configuration
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Read/write connections with endpoint-specific databases or URLs cannot be automatically managed during parallel testing. '
            . 'Configure a single database identity or run with --without-databases.'
        );

        $this->whenNotUsingInMemoryDatabase(
            'mysql',
            'testing',
            false,
            static function (): void {
            },
            $configuration,
            false,
        );
    }

    public static function unsupportedReadWriteConfigurations(): iterable
    {
        yield 'associative read database' => [[
            'read' => ['database' => 'reader'],
        ]];

        yield 'associative write URL' => [[
            'write' => ['url' => 'mysql://writer:secret@write-host/writer'],
        ]];

        yield 'list read database' => [[
            'read' => [
                ['host' => 'read-one'],
                ['database' => 'reader'],
            ],
        ]];

        yield 'query-derived read database' => [[
            'url' => 'mysql://worker:secret@host/testing?read[database]=reader',
        ]];
    }

    protected function switchToDatabase(string $database): void
    {
        $instance = new class {
            use TestDatabases;
        };

        $method = new ReflectionMethod($instance, 'switchToDatabase');
        $method->invoke($instance, $database);
    }

    protected function testDatabase(string $database): string
    {
        $instance = new class {
            use TestDatabases;
        };

        $method = new ReflectionMethod($instance, 'testDatabase');

        return $method->invoke($instance, $database);
    }

    protected function whenNotUsingInMemoryDatabase(
        string $driver,
        string $database,
        bool $withoutDatabases,
        callable $callback,
        array $configuration = [],
        bool $expectsConnectionLookup = true,
    ): void {
        $db = m::mock(DatabaseManager::class);

        if ($expectsConnectionLookup) {
            $db->shouldReceive('getConfig')->with('database')->andReturn($database);

            if (! $withoutDatabases) {
                $db->shouldReceive('getConfig')->with('driver')->andReturn($driver);
            }
        } else {
            $db->shouldNotReceive('getConfig');
        }

        Container::getInstance()->instance('db', $db);
        Container::getInstance()->make('config')->set(
            'database.connections.mysql',
            array_replace([
                'driver' => $driver,
                'database' => $database,
            ], $configuration),
        );
        Container::getInstance()
            ->make(ParallelTesting::class)
            ->resolveOptionsUsing(
                fn (string $option): bool => $option === 'without_databases' && $withoutDatabases
            );

        $instance = new class {
            use TestDatabases;
        };

        $method = new ReflectionMethod($instance, 'whenNotUsingInMemoryDatabase');
        $method->invoke($instance, $callback);
    }
}
