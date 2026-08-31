<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Builder;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PDO;

class DatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;

    private ?Container $app;

    private ?array $tablesToTruncate = null;

    private ?array $exceptTables = null;

    private array $connectionsToTruncate = [null];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container;
        $this->app->instance('config', new Repository([
            'database' => [
                'migrations' => [
                    'table' => 'migrations',
                    'update_date_on_publish' => true,
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $this->app = null;
        static::$allTables = [];
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->tablesToTruncate = null;
        $this->exceptTables = null;
        $this->connectionsToTruncate = [null];

        parent::tearDown();
    }

    public function testTruncateTables()
    {
        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => null, 'name' => 'foo', 'schema_qualified_name' => 'foo'],
            ['schema' => null, 'name' => 'bar', 'schema_qualified_name' => 'bar'],
        ]);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['foo', 'bar'], $truncatedTables);
    }

    public function testTruncateTablesWithTablesToTruncateProperty()
    {
        $this->tablesToTruncate = ['foo', 'bar', 'qux'];

        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => null, 'name' => 'migrations', 'schema_qualified_name' => 'migrations'],
            ['schema' => null, 'name' => 'foo', 'schema_qualified_name' => 'foo'],
            ['schema' => null, 'name' => 'bar', 'schema_qualified_name' => 'bar'],
            ['schema' => null, 'name' => 'baz', 'schema_qualified_name' => 'baz'],
        ]);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['foo', 'bar'], $truncatedTables);
    }

    public function testTruncateTablesWithExceptTablesProperty()
    {
        $this->exceptTables = ['baz', 'qux'];

        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => null, 'name' => 'migrations', 'schema_qualified_name' => 'migrations'],
            ['schema' => null, 'name' => 'foo', 'schema_qualified_name' => 'foo'],
            ['schema' => null, 'name' => 'bar', 'schema_qualified_name' => 'bar'],
            ['schema' => null, 'name' => 'baz', 'schema_qualified_name' => 'baz'],
        ]);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['foo', 'bar'], $truncatedTables);
    }

    public function testTruncateTablesWithSchema()
    {
        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => 'public', 'name' => 'migrations', 'schema_qualified_name' => 'public.migrations'],
            ['schema' => 'public', 'name' => 'foo', 'schema_qualified_name' => 'public.foo'],
            ['schema' => 'public', 'name' => 'bar', 'schema_qualified_name' => 'public.bar'],
            ['schema' => 'private', 'name' => 'migrations', 'schema_qualified_name' => 'private.migrations'],
            ['schema' => 'private', 'name' => 'foo', 'schema_qualified_name' => 'private.foo'],
            ['schema' => 'private', 'name' => 'baz', 'schema_qualified_name' => 'private.baz'],
        ]);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['public.foo', 'public.bar', 'private.foo', 'private.baz'], $truncatedTables);
    }

    public function testTruncateTablesWithSchemaTablesToTruncateProperty()
    {
        $this->tablesToTruncate = ['foo', 'public.bar'];

        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => 'public', 'name' => 'migrations', 'schema_qualified_name' => 'public.migrations'],
            ['schema' => 'public', 'name' => 'foo', 'schema_qualified_name' => 'public.foo'],
            ['schema' => 'public', 'name' => 'bar', 'schema_qualified_name' => 'public.bar'],
            ['schema' => 'public', 'name' => 'baz', 'schema_qualified_name' => 'public.baz'],
            ['schema' => 'private', 'name' => 'migrations', 'schema_qualified_name' => 'private.migrations'],
            ['schema' => 'private', 'name' => 'foo', 'schema_qualified_name' => 'private.foo'],
            ['schema' => 'private', 'name' => 'bar', 'schema_qualified_name' => 'private.bar'],
        ]);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['public.foo', 'public.bar', 'private.foo'], $truncatedTables);
    }

    public function testTruncateTablesWithSchemaAndExceptTablesProperty()
    {
        $this->exceptTables = ['foo', 'public.bar'];

        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => 'public', 'name' => 'migrations', 'schema_qualified_name' => 'public.migrations'],
            ['schema' => 'public', 'name' => 'foo', 'schema_qualified_name' => 'public.foo'],
            ['schema' => 'public', 'name' => 'bar', 'schema_qualified_name' => 'public.bar'],
            ['schema' => 'public', 'name' => 'baz', 'schema_qualified_name' => 'public.baz'],
            ['schema' => 'private', 'name' => 'migrations', 'schema_qualified_name' => 'private.migrations'],
            ['schema' => 'private', 'name' => 'foo', 'schema_qualified_name' => 'private.foo'],
            ['schema' => 'private', 'name' => 'bar', 'schema_qualified_name' => 'private.bar'],
        ]);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['public.baz', 'private.bar'], $truncatedTables);
    }

    public function testTruncateTablesWithConnectionPrefix()
    {
        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => 'public', 'name' => 'my_migrations', 'schema_qualified_name' => 'public.my_migrations'],
            ['schema' => 'public', 'name' => 'my_foo', 'schema_qualified_name' => 'public.my_foo'],
            ['schema' => 'public', 'name' => 'my_baz', 'schema_qualified_name' => 'public.my_baz'],
            ['schema' => 'private', 'name' => 'my_migrations', 'schema_qualified_name' => 'private.my_migrations'],
            ['schema' => 'private', 'name' => 'my_foo', 'schema_qualified_name' => 'private.my_foo'],
        ], 'my_');

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['public.my_foo', 'public.my_baz', 'private.my_foo'], $truncatedTables);
    }

    public function testTruncateTablesOnPgsqlWithSearchPath()
    {
        $connection = $this->arrangeConnection($truncatedTables, [
            ['schema' => 'public', 'name' => 'migrations', 'schema_qualified_name' => 'public.migrations'],
            ['schema' => 'public', 'name' => 'foo', 'schema_qualified_name' => 'public.foo'],
            ['schema' => 'public', 'name' => 'bar', 'schema_qualified_name' => 'public.bar'],
            ['schema' => 'my_schema', 'name' => 'foo', 'schema_qualified_name' => 'my_schema.foo'],
            ['schema' => 'my_schema', 'name' => 'baz', 'schema_qualified_name' => 'my_schema.baz'],
            ['schema' => 'private', 'name' => 'migrations', 'schema_qualified_name' => 'private.migrations'],
            ['schema' => 'private', 'name' => 'foo', 'schema_qualified_name' => 'private.foo'],
            ['schema' => 'private', 'name' => 'baz', 'schema_qualified_name' => 'private.baz'],
        ], '', PostgresBuilder::class, ['my_schema', 'public']);

        $this->truncateTablesForConnection($connection, 'test');

        $this->assertEquals(['public.foo', 'public.bar', 'my_schema.foo', 'my_schema.baz'], $truncatedTables);
    }

    public function testRestoreSkipsDatabaseResolutionWhenNoInMemoryConnectionIsCached(): void
    {
        $this->restoreInMemoryDatabases();

        $this->assertFalse($this->app->resolved('db'));
    }

    public function testCachesAndRestoresConfiguredInMemoryConnections(): void
    {
        $defaultPdo = m::mock(PDO::class);
        $namedPdo = m::mock(PDO::class);
        $sourceDefault = m::mock(PdoConnection::class);
        $sourceNamed = m::mock(PdoConnection::class);
        $sourceDefault->shouldReceive('getPdo')->once()->andReturn($defaultPdo);
        $sourceNamed->shouldReceive('getPdo')->once()->andReturn($namedPdo);

        $sourceDatabase = m::mock(DatabaseManager::class);
        $sourceDatabase->shouldReceive('connection')->once()->with(null)->andReturn($sourceDefault);
        $sourceDatabase->shouldReceive('connection')->once()->with('named')->andReturn($sourceNamed);
        $sourceDatabase->shouldNotReceive('connection')->with('file');

        $this->app->instance('config', new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'named' => ['driver' => 'sqlite', 'database' => 'file::memory:?cache=shared'],
                    'file' => ['driver' => 'sqlite', 'database' => '/tmp/database.sqlite'],
                ],
            ],
        ]));
        $this->app->instance('db', $sourceDatabase);
        $this->connectionsToTruncate = [null, 'named', 'file'];

        $this->cacheInMemoryDatabases();

        $dispatcher = m::mock(Dispatcher::class);
        $restoredDefault = m::mock(PdoConnection::class);
        $restoredNamed = m::mock(PdoConnection::class);
        $restoredDefault->shouldReceive('setPdo')->once()->with($defaultPdo)->andReturnSelf();
        $restoredDefault->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->andReturnSelf();
        $restoredNamed->shouldReceive('setPdo')->once()->with($namedPdo)->andReturnSelf();
        $restoredNamed->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->andReturnSelf();

        $restoredDatabase = m::mock(DatabaseManager::class);
        $restoredDatabase->shouldReceive('connection')->once()->with(null)->andReturn($restoredDefault);
        $restoredDatabase->shouldReceive('connection')->once()->with('named')->andReturn($restoredNamed);
        $restoredDatabase->shouldNotReceive('connection')->with('file');

        $this->app->instance('db', $restoredDatabase);
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->restoreInMemoryDatabases();

        $this->connectionsToTruncate = ['named', 'file'];

        $this->assertTrue($this->usingInMemoryDatabasesForTruncation());

        $this->connectionsToTruncate = ['missing'];

        $this->assertFalse($this->usingInMemoryDatabasesForTruncation());
        $this->assertSame([
            'default' => $defaultPdo,
            'named' => $namedPdo,
        ], RefreshDatabaseState::$inMemoryConnections);
    }

    public function testCachingInMemoryConnectionRequiresPdoConnection(): void
    {
        $this->app->instance('config', new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn(m::mock(Connection::class));
        $this->app->instance('db', $database);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('In-memory SQLite database testing requires a PDO-backed connection.');

        $this->cacheInMemoryDatabases();
    }

    public function testRestoringInMemoryConnectionRequiresPdoConnection(): void
    {
        $this->app->instance('config', new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        RefreshDatabaseState::$inMemoryConnections = ['default' => m::mock(PDO::class)];

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn(m::mock(Connection::class));
        $this->app->instance('db', $database);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('In-memory SQLite database testing requires a PDO-backed connection.');

        $this->restoreInMemoryDatabases();
    }

    public function testDatabaseMigrationsCannotBeCombinedWithDatabaseTruncation(): void
    {
        $testCase = new class {
            use DatabaseMigrations;
            use DatabaseTruncation;

            public function truncate(): void
            {
                $this->truncateDatabaseTables();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DatabaseTruncation cannot be combined with DatabaseMigrations.');

        $testCase->truncate();
    }

    public function testLazilyRefreshDatabaseCannotBeCombinedWithDatabaseTruncation(): void
    {
        $testCase = new class {
            use DatabaseTruncation;
            use LazilyRefreshDatabase;

            public function truncate(): void
            {
                $this->truncateDatabaseTables();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DatabaseTruncation cannot be combined with LazilyRefreshDatabase.');

        $testCase->truncate();
    }

    public function testAutomaticSeedingCannotCombineRefreshDatabaseWithDatabaseTruncation(): void
    {
        $testCase = new class {
            use DatabaseTruncation;
            use RefreshDatabase;

            protected bool $seed = true;

            public function truncate(): void
            {
                $this->truncateDatabaseTables();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Automatic database seeding is not supported when DatabaseTruncation is combined with RefreshDatabase or DatabaseTransactions.'
        );

        $testCase->truncate();
    }

    public function testAutomaticSeederCannotCombineDatabaseTransactionsWithDatabaseTruncation(): void
    {
        $testCase = new class {
            use DatabaseTransactions;
            use DatabaseTruncation;

            protected string $seeder = 'DatabaseSeeder';

            public function truncate(): void
            {
                $this->truncateDatabaseTables();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Automatic database seeding is not supported when DatabaseTruncation is combined with RefreshDatabase or DatabaseTransactions.'
        );

        $testCase->truncate();
    }

    public function testAdoptsAMissingInMemoryPdoBeforeTheTruncationHook(): void
    {
        $pdo = m::mock(PDO::class);
        $connection = m::mock(PdoConnection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $app = new Container;
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $app->instance('db', $database);

        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = [];

        $testCase = new class($app) {
            use DatabaseTruncation;
            use RefreshDatabase;

            public bool $cacheAvailableBeforeHook = false;

            public function __construct(public Container $app)
            {
            }

            public function truncate(): void
            {
                $this->truncateDatabaseTables();
            }

            protected function beforeTruncatingDatabase(): void
            {
                $this->cacheAvailableBeforeHook = isset(
                    RefreshDatabaseState::$inMemoryConnections['default']
                );
            }

            protected function truncateTablesForAllConnections(): void
            {
            }
        };

        $testCase->truncate();

        $this->assertTrue($testCase->cacheAvailableBeforeHook);
        $this->assertSame(['default' => $pdo], RefreshDatabaseState::$inMemoryConnections);
    }

    private function arrangeConnection(
        ?array &$actual,
        array $allTables,
        string $prefix = '',
        ?string $builder = null,
        ?array $schemas = []
    ): Connection {
        $actual = [];

        $schema = m::mock($builder ?? Builder::class);
        $schema->shouldReceive('getTables')->with($schemas)->once()->andReturn(
            empty($schemas)
                ? $allTables
                : array_filter($allTables, fn ($table) => in_array($table['schema'], $schemas))
        );
        $schema->shouldReceive('getCurrentSchemaListing')->once()->andReturn($schemas);

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn($prefix);
        $connection->shouldReceive('getEventDispatcher')->once()->andReturn($dispatcher = m::mock(Dispatcher::class));
        $connection->shouldReceive('unsetEventDispatcher')->once();
        $connection->shouldReceive('setEventDispatcher')->once()->with($dispatcher);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('withoutTablePrefix')->andReturnUsing(function ($callback) use ($connection) {
            $callback($connection);
        });
        $connection->shouldReceive('table')
            ->andReturnUsing(function (string $tableName) use (&$actual) {
                $actual[] = $tableName;

                $table = m::mock(QueryBuilder::class);
                $table->shouldReceive('exists')->andReturnTrue();
                $table->shouldReceive('truncate');

                return $table;
            });

        return $connection;
    }
}
