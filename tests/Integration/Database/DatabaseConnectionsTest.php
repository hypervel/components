<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\QueryException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\DB;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;

class DatabaseConnectionsTest extends DatabaseTestCase
{
    protected static string $databaseDirectory;

    protected static string $readPath;

    protected static string $writePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $filesystem = new Filesystem;
        static::$databaseDirectory = ParallelTesting::tempDir('DatabaseConnectionsTest');
        $filesystem->ensureDirectoryExists(static::$databaseDirectory);

        static::$readPath = static::$databaseDirectory . '/read.sqlite';
        static::$writePath = static::$databaseDirectory . '/write.sqlite';
        touch(static::$readPath);
        touch(static::$writePath);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(static::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        // Configure a basic sqlite connection for testConnectionsWithoutReadWriteConfigurationAlwaysShowAsWrite
        // (When running with Postgres, DB_DATABASE=testing would be used, causing SQLite to fail)
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure a read/write split connection for tests
        $config->set('database.connections.sqlite_readwrite', [
            'driver' => 'sqlite',
            'read' => [
                'database' => static::$readPath,
            ],
            'write' => [
                'database' => static::$writePath,
            ],
        ]);
    }

    // REMOVED: testBuildDatabaseConnection - Dynamic connections incompatible with Swoole connection pooling

    // REMOVED: testEstablishDatabaseConnection - Dynamic connections incompatible with Swoole connection pooling

    // REMOVED: testThrowExceptionIfConnectionAlreadyExists - Dynamic connections incompatible with Swoole connection pooling

    // REMOVED: testOverrideExistingConnection - Dynamic connections incompatible with Swoole connection pooling

    // REMOVED: testEstablishingAConnectionWillDispatchAnEvent - Uses connectUsing() which is incompatible with Swoole connection pooling

    // REMOVED: testDirectDatabaseConnection - Laravel's ::direct suffix is replaced by Hypervel's migrations_connection setting.

    public function testTablePrefix(): void
    {
        DB::setTablePrefix('prefix_');
        $this->assertSame('prefix_', DB::getTablePrefix());

        DB::withoutTablePrefix(function ($connection) {
            $this->assertSame('', $connection->getTablePrefix());
        });

        $this->assertSame('prefix_', DB::getTablePrefix());

        DB::setTablePrefix('');
        $this->assertSame('', DB::getTablePrefix());
    }

    // REMOVED: testDynamicConnectionDoesntFailOnReconnect - Dynamic connections incompatible with Swoole connection pooling

    // REMOVED: testDynamicConnectionWithNoNameDoesntFailOnReconnect - Dynamic connections incompatible with Swoole connection pooling

    public function testReadWriteTypeIsProvidedInQueryExecutedEventAndQueryLog(): void
    {
        $this->assertQueryReadWriteTypes('sqlite_readwrite', ['write', 'read', 'write', 'read']);
        $this->assertQueryReadWriteTypes('sqlite_readwrite::read', ['read', 'read', 'read', 'read']);
        $this->assertQueryReadWriteTypes('sqlite_readwrite::write', ['write', 'write', 'write', 'write']);
    }

    public function testConnectionsWithoutReadWriteConfigurationAlwaysShowAsWrite(): void
    {
        $this->assertQueryReadWriteTypes('sqlite', ['write', 'write', 'write', 'write']);
        $this->assertQueryReadWriteTypes('sqlite::read', ['write', 'write', 'write', 'write']);
        $this->assertQueryReadWriteTypes('sqlite::write', ['write', 'write', 'write', 'write']);
    }

    public function testDirectConnectionSuffixIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Database connection suffix [::direct] is not supported. Configure a direct connection and use migrations_connection instead.'
        );

        DB::connection('default::direct');
    }

    protected function assertQueryReadWriteTypes(string $connectionName, array $expected): void
    {
        $connection = DB::connection($connectionName);

        $events = collect();
        $connection->listen($events->push(...));
        $connection->enableQueryLog();

        $connection->statement('select 1');
        $this->assertSame($expected[0], $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame($expected[1], $events->shift()->readWriteType);

        $connection->statement('select 1');
        $this->assertSame($expected[2], $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame($expected[3], $events->shift()->readWriteType);

        $this->assertEmpty($events);
        $this->assertSame([
            ['query' => 'select 1', 'readWriteType' => $expected[0]],
            ['query' => 'select 1', 'readWriteType' => $expected[1]],
            ['query' => 'select 1', 'readWriteType' => $expected[2]],
            ['query' => 'select 1', 'readWriteType' => $expected[3]],
        ], Arr::select($connection->getQueryLog(), [
            'query', 'readWriteType',
        ]));
    }

    public function testQueryExceptionsProvideReadWriteType(): void
    {
        try {
            DB::connection('sqlite_readwrite')->select('xxxx', useReadPdo: true);
            $this->fail();
        } catch (QueryException $exception) {
            $this->assertSame('read', $exception->readWriteType);
        }

        try {
            DB::connection('sqlite_readwrite')->select('xxxx', useReadPdo: false);
            $this->fail();
        } catch (QueryException $exception) {
            $this->assertSame('write', $exception->readWriteType);
        }

        try {
            DB::connection('sqlite_readwrite::read')->select('xxxx', useReadPdo: false);
            $this->fail();
        } catch (QueryException $exception) {
            $this->assertSame('read', $exception->readWriteType);
        }

        try {
            DB::connection('sqlite_readwrite::write')->select('xxxx', useReadPdo: true);
            $this->fail();
        } catch (QueryException $exception) {
            $this->assertSame('write', $exception->readWriteType);
        }
    }

    public function testQueryInEventListenerCannotInterfereWithReadWriteType(): void
    {
        $connection = DB::connection('sqlite_readwrite');

        $events = collect();
        $connection->listen($events->push(...));
        $connection->enableQueryLog();

        $connection->listen(function ($query) use ($connection) {
            if ($query->sql === 'select 1') {
                $connection->select('select 2');
            }
        });

        $connection->statement('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);
        $this->assertSame('read', $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame('read', $events->shift()->readWriteType);
        $this->assertSame('read', $events->shift()->readWriteType);

        $connection->statement('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);
        $this->assertSame('read', $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame('read', $events->shift()->readWriteType);
        $this->assertSame('read', $events->shift()->readWriteType);

        $this->assertSame([
            ['query' => 'select 2', 'readWriteType' => 'read'],
            ['query' => 'select 1', 'readWriteType' => 'write'],
            ['query' => 'select 2', 'readWriteType' => 'read'],
            ['query' => 'select 1', 'readWriteType' => 'read'],
            ['query' => 'select 2', 'readWriteType' => 'read'],
            ['query' => 'select 1', 'readWriteType' => 'write'],
            ['query' => 'select 2', 'readWriteType' => 'read'],
            ['query' => 'select 1', 'readWriteType' => 'read'],
        ], Arr::select($connection->getQueryLog(), [
            'query', 'readWriteType',
        ]));
    }
}
