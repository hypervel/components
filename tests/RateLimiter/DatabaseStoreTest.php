<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\DatabaseStore;
use Hypervel\RateLimiter\Limit;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseStoreTest extends TestCase
{
    use VerifiesDoubles;

    public function testInspectionUsesTheConfiguredConnectionTableAndWritePdo(): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('table')->with('custom_rate_limits')->returns($query);
        $query->expects('useWritePdo');
        $query->expects('where')->with('key', 'physical-key');
        $query->expects('first')->returns(null);
        $connection->expects('getDriverName')->returns('pgsql');
        $connection->expects('scalar')
            ->with(
                'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint',
                [],
                false,
            )
            ->returns('1000000');

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->inspect('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
    }

    public function testEstablishedNonSqlMutationLocksBeforeReadingServerTimeWithoutInserting(): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $locked = Double::for(Builder::class)->strict();
        $update = Double::for(Builder::class)->strict();
        $operations = [];

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')
            ->with(Argument::type(Closure::class), 3)
            ->resolves(static fn (Closure $callback): mixed => $callback($connection));
        $connection->expects('getDriverName')
            ->times(3)
            ->resolves(static function () use (&$operations): string {
                $operations[] = 'driver';

                return 'mysql';
            });
        $connection->expects('table')
            ->times(2)
            ->with('custom_rate_limits')
            ->returns($locked, $update);
        $locked->expects('where')->with('key', 'physical-key');
        $locked->expects('lockForUpdate');
        $locked->expects('first')->resolves(static function () use (&$operations): object {
            $operations[] = 'lock';

            return (object) [
                'value' => 0,
                'secondary_value' => 0,
                'expires_at' => 0,
            ];
        });
        $connection->expects('scalar')
            ->with(
                'SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)',
                [],
                false,
            )
            ->resolves(static function () use (&$operations): string {
                $operations[] = 'clock';

                return '1000000';
            });
        $update->expects('where')->with('key', 'physical-key');
        $update->expects('update')
            ->with([
                'value' => 1,
                'secondary_value' => 0,
                'expires_at' => 61_000_000,
            ])
            ->resolves(static function () use (&$operations): int {
                $operations[] = 'update';

                return 1;
            });

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->consume('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(9, $result->remaining());
        $this->assertSame(['driver', 'driver', 'lock', 'driver', 'clock', 'update'], $operations);
    }

    #[DataProvider('nonSqliteDrivers')]
    public function testMissingNonSqliteStateIsInitializedAndMutatedInASecondTransaction(
        string $driver,
        string $clockQuery,
    ): void {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $missing = Double::for(Builder::class)->strict();
        $initialize = Double::for(Builder::class)->strict();
        $locked = Double::for(Builder::class)->strict();
        $update = Double::for(Builder::class)->strict();
        $operations = [];
        $transaction = 0;

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')
            ->times(2)
            ->with(Argument::type(Closure::class), 3)
            ->resolves(static function (Closure $callback) use ($connection, &$operations, &$transaction): mixed {
                ++$transaction;
                $operations[] = "transaction-{$transaction}-begin";
                $result = $callback($connection);
                $operations[] = "transaction-{$transaction}-commit";

                return $result;
            });
        $connection->expects('getDriverName')
            ->times(3)
            ->resolves(static function () use ($driver, &$operations): string {
                $operations[] = 'driver';

                return $driver;
            });

        if ($driver === 'pgsql') {
            $connection->expects('getConfig')
                ->with('isolation_level')
                ->returns('READ COMMITTED');
        } else {
            $connection->expects('getConfig')->never();
        }

        $connection->expects('table')
            ->times(4)
            ->with('custom_rate_limits')
            ->returns($missing, $initialize, $locked, $update);
        $missing->expects('where')->with('key', 'physical-key');
        $missing->expects('lockForUpdate');
        $missing->expects('first')->resolves(static function () use (&$operations): null {
            $operations[] = 'missing-lock';

            return null;
        });
        $initialize->expects('upsert')
            ->with(
                [
                    'key' => 'physical-key',
                    'value' => 0,
                    'secondary_value' => 0,
                    'expires_at' => 0,
                ],
                'key',
                ['key' => 'physical-key'],
            )
            ->resolves(static function () use (&$operations): int {
                $operations[] = 'upsert';

                return 0;
            });
        $locked->expects('where')->with('key', 'physical-key');
        $locked->expects('lockForUpdate');
        $locked->expects('first')->resolves(static function () use (&$operations): object {
            $operations[] = 'final-lock';

            return (object) [
                'value' => 0,
                'secondary_value' => 0,
                'expires_at' => 0,
            ];
        });
        $connection->expects('scalar')
            ->with(
                $clockQuery,
                [],
                false,
            )
            ->resolves(static function () use (&$operations): string {
                $operations[] = 'clock';

                return '1000000';
            });
        $update->expects('where')->with('key', 'physical-key');
        $update->expects('update')
            ->with([
                'value' => 1,
                'secondary_value' => 0,
                'expires_at' => 61_000_000,
            ])
            ->resolves(static function () use (&$operations): int {
                $operations[] = 'update';

                return 1;
            });

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->consume('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(9, $result->remaining());
        $this->assertSame(
            [
                'driver',
                'transaction-1-begin',
                'driver',
                'missing-lock',
                'transaction-1-commit',
                'transaction-2-begin',
                'upsert',
                'final-lock',
                'driver',
                'clock',
                'update',
                'transaction-2-commit',
            ],
            $operations,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonSqliteDrivers(): array
    {
        return [
            'MySQL' => [
                'mysql',
                'SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)',
            ],
            'MariaDB' => [
                'mariadb',
                'SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)',
            ],
            'PostgreSQL' => [
                'pgsql',
                'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint',
            ],
        ];
    }

    public function testSqliteMutationInsertsBeforeReadingTheLockedState(): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $insert = Double::for(Builder::class)->strict();
        $locked = Double::for(Builder::class)->strict();
        $update = Double::for(Builder::class)->strict();
        $operations = [];

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')
            ->with(Argument::type(Closure::class), 3)
            ->resolves(static fn (Closure $callback): mixed => $callback($connection));
        $connection->expects('getDriverName')
            ->times(3)
            ->resolves(static function () use (&$operations): string {
                $operations[] = 'driver';

                return 'sqlite';
            });
        $connection->expects('table')
            ->times(3)
            ->with('custom_rate_limits')
            ->returns($insert, $locked, $update);
        $insert->expects('insertOrIgnore')
            ->with([
                'key' => 'physical-key',
                'value' => 0,
                'secondary_value' => 0,
                'expires_at' => 0,
            ])
            ->resolves(static function () use (&$operations): int {
                $operations[] = 'insert';

                return 1;
            });
        $locked->expects('where')->with('key', 'physical-key');
        $locked->expects('lockForUpdate');
        $locked->expects('first')->resolves(static function () use (&$operations): object {
            $operations[] = 'lock';

            return (object) [
                'value' => 0,
                'secondary_value' => 0,
                'expires_at' => 0,
            ];
        });
        $update->expects('where')->with('key', 'physical-key');
        $update->expects('update')
            ->with(Argument::satisfies(static function (array $state) use (&$operations): bool {
                $operations[] = 'update';

                return $state['value'] === 1
                    && $state['secondary_value'] === 0
                    && $state['expires_at'] > 60_000_000;
            }))
            ->returns(1);

        $result = (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))
            ->consume('physical-key', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(9, $result->remaining());
        $this->assertSame(['driver', 'driver', 'insert', 'lock', 'driver', 'update'], $operations);
    }

    #[DataProvider('mutatingOperations')]
    public function testMutatingOperationsRejectUnsupportedPostgresIsolation(string $operation): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDriverName')->returns('pgsql');
        $connection->expects('getConfig')
            ->with('isolation_level')
            ->returns('repeatable read');
        $connection->expects('getName')->returns('limiter');
        $connection->expects('transaction')->never();
        $connection->expects('table')->never();
        $connection->expects('scalar')->never();

        $store = new DatabaseStore($connections, 'limiter', 'custom_rate_limits');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'PostgreSQL database rate limiter connection [limiter] must use READ COMMITTED transaction isolation.'
        );

        match ($operation) {
            'consume' => $store->consume('physical-key', Limit::perMinute(10)),
            'consumeMany' => $store->consumeMany([['key' => 'physical-key', 'policy' => Limit::perMinute(10)]]),
            'block' => $store->block('physical-key', 1_000_000),
            'recordFailure' => $store->recordFailure('physical-key', Backoff::exponential()),
            'clear' => $store->clear('physical-key'),
            'pruneExpired' => $store->pruneExpired(),
        };
    }

    public function testMutationRejectsNonStringPostgresIsolation(): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDriverName')->returns('pgsql');
        $connection->expects('getConfig')->with('isolation_level')->returns(1);
        $connection->expects('getName')->returns('limiter');
        $connection->expects('table')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'PostgreSQL database rate limiter connection [limiter] must use READ COMMITTED transaction isolation.'
        );

        (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))->clear('physical-key');
    }

    public function testPostgresMutationAllowsDefaultIsolation(): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDriverName')->returns('pgsql');
        $connection->expects('getConfig')->with('isolation_level')->returns(null);
        $connection->expects('getName')->never();
        $connection->expects('table')->with('custom_rate_limits')->returns($query);
        $query->expects('where')->with('key', 'physical-key');
        $query->expects('delete')->returns(1);

        $this->assertTrue(
            (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))->clear('physical-key')
        );
    }

    public function testMysqlMutationDoesNotInspectPostgresIsolationConfiguration(): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDriverName')->returns('mysql');
        $connection->expects('getConfig')->never();
        $connection->expects('table')->with('custom_rate_limits')->returns($query);
        $query->expects('where')->with('key', 'physical-key');
        $query->expects('delete')->returns(1);

        $this->assertTrue(
            (new DatabaseStore($connections, 'limiter', 'custom_rate_limits'))->clear('physical-key')
        );
    }

    #[DataProvider('mutatingOperations')]
    public function testMutatingOperationsRejectAnActiveTransactionBeforeLimiterSql(string $operation): void
    {
        $connections = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();

        $connections->expects('connection')->with('limiter')->returns($connection);
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('transaction')->never();
        $connection->expects('table')->never();
        $connection->expects('getDriverName')->never();
        $connection->expects('scalar')->never();

        $store = new DatabaseStore($connections, 'limiter', 'custom_rate_limits');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Database rate limiter mutations cannot run inside an active transaction on the selected connection.'
        );

        match ($operation) {
            'consume' => $store->consume('physical-key', Limit::perMinute(10)),
            'consumeMany' => $store->consumeMany([['key' => 'physical-key', 'policy' => Limit::perMinute(10)]]),
            'block' => $store->block('physical-key', 1_000_000),
            'recordFailure' => $store->recordFailure('physical-key', Backoff::exponential()),
            'clear' => $store->clear('physical-key'),
            'pruneExpired' => $store->pruneExpired(),
        };
    }

    public static function mutatingOperations(): array
    {
        return [
            'consume' => ['consume'],
            'consume group' => ['consumeMany'],
            'block' => ['block'],
            'record failure' => ['recordFailure'],
            'clear' => ['clear'],
            'prune expired' => ['pruneExpired'],
        ];
    }
}
