<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\QueryException;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseConnectionsTest extends DatabaseTestCase
{
    protected static string $readPath;

    protected static string $writePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create temp SQLite files for read/write splitting tests
        static::$readPath = sys_get_temp_dir() . '/hypervel_test_read_' . uniqid() . '.sqlite';
        static::$writePath = sys_get_temp_dir() . '/hypervel_test_write_' . uniqid() . '.sqlite';
        touch(static::$readPath);
        touch(static::$writePath);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(static::$readPath);
        @unlink(static::$writePath);

        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        // Configure a basic sqlite connection for testConnectionsWithoutReadWriteConfigurationAlwaysShowAsWrite
        // (When running with Postgres, DB_DATABASE=testing would be used, causing SQLite to fail)
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure a read/write split connection for tests
        $app['config']->set('database.connections.sqlite_readwrite', [
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
        $connection = DB::connection('sqlite_readwrite');

        $events = collect();
        $connection->listen($events->push(...));
        $connection->enableQueryLog();

        $connection->statement('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame('read', $events->shift()->readWriteType);

        $connection->statement('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame('read', $events->shift()->readWriteType);

        $this->assertEmpty($events);
        $this->assertSame([
            ['query' => 'select 1', 'readWriteType' => 'write'],
            ['query' => 'select 1', 'readWriteType' => 'read'],
            ['query' => 'select 1', 'readWriteType' => 'write'],
            ['query' => 'select 1', 'readWriteType' => 'read'],
        ], Arr::select($connection->getQueryLog(), [
            'query', 'readWriteType',
        ]));
    }

    public function testConnectionsWithoutReadWriteConfigurationAlwaysShowAsWrite(): void
    {
        // Default sqlite connection has no read/write splitting
        $connection = DB::connection('sqlite');

        $events = collect();
        $connection->listen($events->push(...));

        $connection->statement('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);

        $connection->statement('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);

        $connection->select('select 1');
        $this->assertSame('write', $events->shift()->readWriteType);
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
