<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Closure;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\DatabaseStore;
use Hypervel\RateLimiter\Limit;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseStoreTest extends TestCase
{
    public function testInspectionUsesTheConfiguredConnectionTableAndWritePdo(): void
    {
        $connections = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);

        $connections->shouldReceive('connection')->once()->with('limiter')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('custom_rate_limits')->andReturn($query);
        $query->shouldReceive('useWritePdo')->once()->andReturnSelf();
        $query->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturnNull();
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('scalar')
            ->once()
            ->with(
                'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint',
                [],
                false,
            )
            ->andReturn('1000000');

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->inspect('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
    }

    public function testEstablishedNonSqlMutationLocksBeforeReadingServerTimeWithoutInserting(): void
    {
        $connections = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $locked = m::mock(Builder::class);
        $update = m::mock(Builder::class);
        $operations = [];

        $connections->shouldReceive('connection')->once()->with('limiter')->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(m::type(Closure::class), 3)
            ->andReturnUsing(static fn (Closure $callback): mixed => $callback($connection));
        $connection->shouldReceive('getDriverName')
            ->twice()
            ->andReturnUsing(static function () use (&$operations): string {
                $operations[] = 'driver';

                return 'mysql';
            });
        $connection->shouldReceive('table')
            ->twice()
            ->with('custom_rate_limits')
            ->andReturn($locked, $update);
        $locked->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $locked->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $locked->shouldReceive('first')->once()->andReturnUsing(static function () use (&$operations): object {
            $operations[] = 'lock';

            return (object) [
                'value' => 0,
                'available_at' => 0,
                'expires_at' => 0,
            ];
        });
        $connection->shouldReceive('scalar')
            ->once()
            ->with(
                'SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)',
                [],
                false,
            )
            ->andReturnUsing(static function () use (&$operations): string {
                $operations[] = 'clock';

                return '1000000';
            });
        $update->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $update->shouldReceive('update')
            ->once()
            ->with([
                'value' => 1,
                'available_at' => 61_000_000,
                'expires_at' => 61_000_000,
            ])
            ->andReturnUsing(static function () use (&$operations): int {
                $operations[] = 'update';

                return 1;
            });

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->consume('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(9, $result->remaining());
        $this->assertSame(['driver', 'lock', 'driver', 'clock', 'update'], $operations);
    }

    public function testMissingNonSqlStateIsInsertedBetweenTheInitialAndFinalRowLocks(): void
    {
        $connections = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $missing = m::mock(Builder::class);
        $insert = m::mock(Builder::class);
        $locked = m::mock(Builder::class);
        $update = m::mock(Builder::class);
        $operations = [];

        $connections->shouldReceive('connection')->once()->with('limiter')->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(m::type(Closure::class), 3)
            ->andReturnUsing(static fn (Closure $callback): mixed => $callback($connection));
        $connection->shouldReceive('getDriverName')
            ->twice()
            ->andReturnUsing(static function () use (&$operations): string {
                $operations[] = 'driver';

                return 'pgsql';
            });
        $connection->shouldReceive('table')
            ->times(4)
            ->with('custom_rate_limits')
            ->andReturn($missing, $insert, $locked, $update);
        $missing->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $missing->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $missing->shouldReceive('first')->once()->andReturnUsing(static function () use (&$operations): null {
            $operations[] = 'missing-lock';

            return null;
        });
        $insert->shouldReceive('insertOrIgnore')
            ->once()
            ->with([
                'key' => 'physical-key',
                'value' => 0,
                'available_at' => 0,
                'expires_at' => 0,
            ])
            ->andReturnUsing(static function () use (&$operations): int {
                $operations[] = 'insert';

                return 1;
            });
        $locked->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $locked->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $locked->shouldReceive('first')->once()->andReturnUsing(static function () use (&$operations): object {
            $operations[] = 'final-lock';

            return (object) [
                'value' => 0,
                'available_at' => 0,
                'expires_at' => 0,
            ];
        });
        $connection->shouldReceive('scalar')
            ->once()
            ->with(
                'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint',
                [],
                false,
            )
            ->andReturnUsing(static function () use (&$operations): string {
                $operations[] = 'clock';

                return '1000000';
            });
        $update->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $update->shouldReceive('update')
            ->once()
            ->with([
                'value' => 1,
                'available_at' => 61_000_000,
                'expires_at' => 61_000_000,
            ])
            ->andReturnUsing(static function () use (&$operations): int {
                $operations[] = 'update';

                return 1;
            });

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->consume('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(9, $result->remaining());
        $this->assertSame(
            ['driver', 'missing-lock', 'insert', 'final-lock', 'driver', 'clock', 'update'],
            $operations,
        );
    }

    public function testSqliteMutationInsertsBeforeReadingTheLockedState(): void
    {
        $connections = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $insert = m::mock(Builder::class);
        $locked = m::mock(Builder::class);
        $update = m::mock(Builder::class);
        $operations = [];

        $connections->shouldReceive('connection')->once()->with('limiter')->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(m::type(Closure::class), 3)
            ->andReturnUsing(static fn (Closure $callback): mixed => $callback($connection));
        $connection->shouldReceive('getDriverName')
            ->twice()
            ->andReturnUsing(static function () use (&$operations): string {
                $operations[] = 'driver';

                return 'sqlite';
            });
        $connection->shouldReceive('table')
            ->times(3)
            ->with('custom_rate_limits')
            ->andReturn($insert, $locked, $update);
        $insert->shouldReceive('insertOrIgnore')
            ->once()
            ->with([
                'key' => 'physical-key',
                'value' => 0,
                'available_at' => 0,
                'expires_at' => 0,
            ])
            ->andReturnUsing(static function () use (&$operations): int {
                $operations[] = 'insert';

                return 1;
            });
        $locked->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $locked->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $locked->shouldReceive('first')->once()->andReturnUsing(static function () use (&$operations): object {
            $operations[] = 'lock';

            return (object) [
                'value' => 0,
                'available_at' => 0,
                'expires_at' => 0,
            ];
        });
        $update->shouldReceive('where')->once()->with('key', 'physical-key')->andReturnSelf();
        $update->shouldReceive('update')
            ->once()
            ->with(m::on(static function (array $state) use (&$operations): bool {
                $operations[] = 'update';

                return $state['value'] === 1
                    && $state['available_at'] > 60_000_000
                    && $state['expires_at'] === $state['available_at'];
            }))
            ->andReturn(1);

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->consume('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(9, $result->remaining());
        $this->assertSame(['driver', 'insert', 'lock', 'driver', 'update'], $operations);
    }

    #[DataProvider('mutatingOperations')]
    public function testMutatingOperationsRejectAnActiveTransactionBeforeLimiterSql(string $operation): void
    {
        $connections = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);

        $connections->shouldReceive('connection')->once()->with('limiter')->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldNotReceive('transaction');
        $connection->shouldNotReceive('table');
        $connection->shouldNotReceive('getDriverName');
        $connection->shouldNotReceive('scalar');

        $store = new DatabaseStore($connections, 'limiter', 'custom_rate_limits');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Database rate limiter mutations cannot run inside an active transaction on the selected connection.'
        );

        match ($operation) {
            'consume' => $store->consume('physical-key', Limit::perMinute(10)),
            'recordFailure' => $store->recordFailure('physical-key', Backoff::exponential()),
            'clear' => $store->clear('physical-key'),
            'pruneExpired' => $store->pruneExpired(),
        };
    }

    public static function mutatingOperations(): array
    {
        return [
            'consume' => ['consume'],
            'record failure' => ['recordFailure'],
            'clear' => ['clear'],
            'prune expired' => ['pruneExpired'],
        ];
    }
}
