<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\QueryException;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseConnectionsTest extends DatabaseTestCase
{
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

    #[DataProvider('readWriteExpectations')]
    public function testReadWriteTypeIsProvidedInQueryExecutedEventAndQueryLog(string $connectionName, array $expectedTypes, ?string $loggedType)
    {
        $readPath = __DIR__.'/read.sqlite';
        $writePath = __DIR__.'/write.sqlite';
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'read' => [
                'database' => $readPath,
            ],
            'write' => [
                'database' => $writePath,
            ],
        ]);
        $events = collect();
        DB::listen($events->push(...));

        try {
            touch($readPath);
            touch($writePath);

            $connection = DB::connection($connectionName);
            $connection->enableQueryLog();

            $connection->statement('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);

            $connection->select('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);

            $connection->statement('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);

            $connection->select('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);

            $this->assertEmpty($events);
            $this->assertSame([
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'write'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'read'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'write'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'read'],
            ], Arr::select($connection->getQueryLog(), [
                'query', 'readWriteType',
            ]));
        } finally {
            @unlink($readPath);
            @unlink($writePath);
        }
    }

    public static function readWriteExpectations(): iterable
    {
        yield 'sqlite' => ['sqlite', ['write', 'read', 'write', 'read'], null];
    }

    public function testConnectionsWithoutReadWriteConfigurationAlwaysShowAsWrite()
    {
        $writePath = __DIR__.'/write.sqlite';
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $writePath,
        ]);
        $events = collect();
        DB::listen($events->push(...));

        try {
            touch($writePath);

            $connection = DB::connection('sqlite');

            $connection->statement('select 1');
            $this->assertSame('write', $events->shift()->readWriteType);

            $connection->select('select 1');
            $this->assertSame('write', $events->shift()->readWriteType);

            $connection->statement('select 1');
            $this->assertSame('write', $events->shift()->readWriteType);

            $connection->select('select 1');
            $this->assertSame('write', $events->shift()->readWriteType);
        } finally {
            @unlink($writePath);
        }
    }

    public function testQueryExceptionsProvideReadWriteType(): void
    {
        $readPath = __DIR__ . '/read.sqlite';
        $writePath = __DIR__ . '/write.sqlite';
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'read' => [
                'database' => $readPath,
            ],
            'write' => [
                'database' => $writePath,
            ],
        ]);

        try {
            touch($readPath);
            touch($writePath);

            try {
                DB::connection('sqlite')->select('xxxx', useReadPdo: true);
                $this->fail();
            } catch (QueryException $exception) {
                $this->assertSame('read', $exception->readWriteType);
            }

            try {
                DB::connection('sqlite')->select('xxxx', useReadPdo: false);
                $this->fail();
            } catch (QueryException $exception) {
                $this->assertSame('write', $exception->readWriteType);
            }
        } finally {
            @unlink($writePath);
            @unlink($readPath);
        }
    }

    #[DataProvider('readWriteExpectations')]
    public function testQueryInEventListenerCannotInterfereWithReadWriteType(string $connectionName, array $expectedTypes, ?string $loggedType)
    {
        $readPath = __DIR__.'/read.sqlite';
        $writePath = __DIR__.'/write.sqlite';
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'read' => [
                'database' => $readPath,
            ],
            'write' => [
                'database' => $writePath,
            ],
        ]);
        $events = collect();
        DB::listen($events->push(...));

        try {
            touch($readPath);
            touch($writePath);

            $connection = DB::connection($connectionName);
            $connection->enableQueryLog();

            $connection->listen(function ($query) use ($connection) {
                if ($query->sql === 'select 1') {
                    $connection->select('select 2');
                }
            });

            $connection->statement('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);
            $this->assertSame($loggedType ?? 'read', $events->shift()->readWriteType);

            $connection->select('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);
            $this->assertSame($loggedType ?? 'read', $events->shift()->readWriteType);

            $connection->statement('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);
            $this->assertSame($loggedType ?? 'read', $events->shift()->readWriteType);

            $connection->select('select 1');
            $this->assertSame(array_shift($expectedTypes), $events->shift()->readWriteType);
            $this->assertSame($loggedType ?? 'read', $events->shift()->readWriteType);

            $this->assertSame([
                ['query' => 'select 2', 'readWriteType' => $loggedType ?? 'read'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'write'],
                ['query' => 'select 2', 'readWriteType' => $loggedType ?? 'read'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'read'],
                ['query' => 'select 2', 'readWriteType' => $loggedType ?? 'read'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'write'],
                ['query' => 'select 2', 'readWriteType' => $loggedType ?? 'read'],
                ['query' => 'select 1', 'readWriteType' => $loggedType ?? 'read'],
            ], Arr::select($connection->getQueryLog(), [
                'query', 'readWriteType',
            ]));
        } finally {
            @unlink($readPath);
            @unlink($writePath);
        }
    }
}
