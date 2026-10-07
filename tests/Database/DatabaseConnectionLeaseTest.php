<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectorInterface;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Engine\Channel;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class DatabaseConnectionLeaseTest extends TestCase
{
    /**
     * Configure a single physical slot shared by independently owned connections.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.leases', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 1.0,
                'events' => [ConnectionReleasing::class],
            ],
        ]);
        $app->make('config')->set('database.connections.physical_leases', [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => ['host' => 'replica'],
            'write' => ['host' => 'primary'],
            'sticky' => true,
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
        $app->make('config')->set('database.connections.identity_leases', [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => [['prefix' => 'first_'], ['prefix' => 'second_']],
            'write' => [['prefix' => 'first_'], ['prefix' => 'second_']],
            'sticky' => true,
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
        $app->make('config')->set('database.connections.insert_id_leases', [
            'driver' => 'mysql',
            'database' => 'app',
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
        $app->instance('db.connector.pgsql', new LeasePdoConnector);
        $app->instance('db.connector.mysql', new LeasePdoConnector);
    }

    public function testRetainedBuildersAndCallerStateSurviveAnotherBorrower(): void
    {
        $connection = DB::connection('leases');
        $schema = $connection->getSchemaBuilder();
        $schema->create('records', static fn (Blueprint $table) => $table->integer('id'));
        $builder = $connection->table('records');
        $builder->insert(['id' => 7]);
        $connection->enableQueryLog();
        $queries = 0;
        $connection->beforeExecuting(static function () use (&$queries): void {
            ++$queries;
        });

        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());

        [$other] = parallel([static function (): Connection {
            $connection = DB::connection('leases');
            $connection->selectOne('select 1');
            $connection->setTablePrefix('other_');
            $connection->setDatabaseName('other');

            return $connection;
        }]);

        $this->assertNotSame($connection, $other);
        $this->assertSame($connection, DB::connection('leases'));
        $this->assertSame('', $connection->getTablePrefix());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame(7, $builder->value('id'));
        $this->assertTrue($schema->hasTable('records'));
        $this->assertTrue($connection->hasModifiedRecords());
        $this->assertTrue($connection->logging());
        $this->assertGreaterThanOrEqual(2, $queries);
        $this->assertCount($queries, $connection->getQueryLog());
    }

    public function testConcurrentExternalWaitsDoNotRetainTheOnlyPoolSlot(): void
    {
        $ready = new Channel(2);
        $resume = new Channel(2);
        $waitForProvider = static function () use ($ready, $resume): int {
            $connection = DB::connection('leases');
            $value = $connection->selectOne('select 1 as value')->value;
            DB::releaseIdleConnections();
            $ready->push(true);

            if ($resume->pop(3.0) !== true) {
                throw new RuntimeException('The simulated provider was not resumed.');
            }

            return $value + $connection->selectOne('select 2 as value')->value;
        };

        $results = parallel([
            $waitForProvider,
            $waitForProvider,
            function () use ($ready, $resume): void {
                try {
                    $this->assertTrue($ready->pop(3.0));
                    $this->assertTrue($ready->pop(3.0));
                    $this->assertSame(1, $this->pool()->getIdleCount());
                } finally {
                    $resume->push(true);
                    $resume->push(true);
                }
            },
        ]);

        $this->assertSame([3, 3, null], $results);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    #[DataProvider('pinnedScopes')]
    public function testSessionDependentScopesPreventEarlyRelease(string $scope): void
    {
        $connection = DB::connection('leases');
        $check = function () use ($connection): void {
            $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
            DB::releaseIdleConnections();
            $this->assertSame(0, $this->pool()->getIdleCount());
        };

        match ($scope) {
            'explicit' => $connection->withPinnedSession(
                fn () => $connection->withPinnedSession($check)
            ),
            'transaction' => $connection->transaction($check),
            'foreign keys' => $connection->getSchemaBuilder()->withoutForeignKeyConstraints($check),
        };

        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    /**
     * Provide scopes whose work requires the same physical session.
     */
    public static function pinnedScopes(): array
    {
        return [['explicit'], ['transaction'], ['foreign keys']];
    }

    public function testAnAbandonedCursorReleasesItsPin(): void
    {
        $connection = DB::connection('leases');
        $cursor = $connection->cursor('select 1 as value union all select 2');
        $this->assertSame(1, $cursor->current()->value);
        DB::releaseIdleConnections();
        $this->assertSame(0, $this->pool()->getIdleCount());

        unset($cursor);
        DB::releaseIdleConnections();

        $this->assertSame(1, $this->pool()->getIdleCount());
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
    }

    public function testConnectionAndQueryListenersKeepTheLogicalOwnerAttached(): void
    {
        $established = null;
        $released = null;
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$established): void {
            if ($event->connection->getName() !== 'leases') {
                return;
            }

            $established = $event->connection;
            DB::releaseIdleConnections();
            $this->assertSame(0, $this->pool()->getIdleCount());
            $this->assertSame(1, $event->connection->selectOne('select 1 as value')->value);
        });
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if ($event->connection->getName() === 'leases') {
                DB::releaseIdleConnections();
                $this->assertSame(0, $this->pool()->getIdleCount());
            }
        });
        Event::listen(ConnectionReleasing::class, function (ConnectionReleasing $event) use (&$released): void {
            $released = $event->connection->getConnection();
            $this->assertSame(2, $released->selectOne('select 2 as value')->value);
        });

        $connection = DB::connection('leases');
        DB::releaseIdleConnections();

        $this->assertSame($connection, $established);
        $this->assertSame($connection, $released);
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    public function testAnEagerPdoResolverCanConstructTheLogicalDriver(): void
    {
        Connection::resolverFor(
            'sqlite',
            static fn (PDO|Closure $pdo, string $database, string $prefix, array $config): LeaseSqliteConnection => new LeaseSqliteConnection($pdo instanceof Closure ? $pdo() : $pdo, $database, $prefix, $config)
        );

        $connection = DB::connection('leases');
        $this->assertInstanceOf(LeaseSqliteConnection::class, $connection);
        $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
    }

    #[DataProvider('differentConfiguredPrefixesProvider')]
    public function testDifferentConfiguredPrefixesRetainWholeConnectionOwnership(string $name): void
    {
        $connection = DB::connection($name);
        $pool = $this->app->make(PoolManager::class)->pool($name);
        $connection->statement('create table ' . $connection->getTablePrefix() . 'records (id integer)');
        $builder = $connection->table('records');
        $builder->insert(['id' => 7]);

        DB::releaseIdleConnections();

        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame($connection, DB::connection($name));
        $this->assertSame(7, $builder->value('id'));
    }

    /**
     * Provide pools whose selectable endpoints use different table prefixes.
     */
    public static function differentConfiguredPrefixesProvider(): array
    {
        return [
            'read records' => ['identity_leases::read'],
            'write records' => ['identity_leases'],
        ];
    }

    public function testDisconnectDropsThePhysicalPdoAndReusesTheLogicalConnection(): void
    {
        $connection = DB::connection('physical_leases');
        $pdo = WeakReference::create($connection->getPdo());
        $readPdo = WeakReference::create($connection->getReadPdo());
        $this->assertNotSame($pdo->get(), $readPdo->get());

        DB::disconnect('physical_leases');

        $this->assertNull($pdo->get());
        $this->assertNull($readPdo->get());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
        $this->assertSame($connection, DB::connection('physical_leases'));
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
    }

    public function testCapturedMySqlInsertIdsSurviveEarlyRelease(): void
    {
        $connection = DB::connection('insert_id_leases');
        $connection->statement('create table records (id integer primary key)');
        $connection->insert('insert into records default values');

        DB::releaseIdleConnections();

        $this->assertSame('1', $connection->getLastInsertId());
        $this->assertSame(1, $this->app->make(PoolManager::class)->pool('insert_id_leases')->getIdleCount());
        $connection->insert('insert into records default values');
        $this->assertSame('2', $connection->getLastInsertId());
    }

    public function testReadWriteRolesAndStickyRoutingSurviveEarlyRelease(): void
    {
        $connection = DB::connection('physical_leases');
        $this->assertNotSame($connection->getPdo(), $connection->getReadPdo());
        $connection->statement('create table records (id integer)');

        DB::releaseIdleConnections();

        [$otherWasSticky] = parallel([static function (): bool {
            $other = DB::connection('physical_leases');

            return $other->getPdo() === $other->getReadPdo();
        }]);

        $this->assertFalse($otherWasSticky);
        $this->assertSame($connection->getPdo(), $connection->getReadPdo());
        DB::releaseIdleConnections();

        $read = DB::connection('physical_leases::read');
        $write = DB::connection('physical_leases::write');
        $this->assertSame('replica', $read->getConfig('host'));
        $this->assertSame('primary', $write->getConfig('host'));
        $this->assertSame($write->getPdo(), $write->getReadPdo());
        DB::releaseIdleConnections();

        $this->assertSame(1, $read->selectOne('select 1 as value')->value);
        $this->assertSame(2, $write->selectOne('select 2 as value')->value);
        $this->assertSame('replica', $read->getConfig('host'));
        $this->assertSame('primary', $write->getConfig('host'));
    }

    public function testConfigFirstExtensionsRetainWholeConnectionOwnership(): void
    {
        $constructions = 0;
        DB::extend('physical_leases', static function (array $config) use (&$constructions): PostgresConnection {
            ++$constructions;

            return new PostgresConnection(new PDO('sqlite::memory:'), $config['database'], '', $config);
        });
        $connection = DB::connection('physical_leases');
        $connection->selectOne('select 1');

        DB::releaseIdleConnections();

        $this->assertSame(0, $this->app->make(PoolManager::class)->pool('physical_leases')->getIdleCount());
        $this->assertSame($connection, DB::connection('physical_leases'));
        $this->assertSame(2, $connection->selectOne('select 2 as value')->value);
        $this->assertSame(1, $constructions);
    }

    public function testSessionCapabilitiesAreResolvedAgainAfterEarlyRelease(): void
    {
        Connection::resolverFor(
            'pgsql',
            static fn (PDO|Closure $pdo, string $database, string $prefix, array $config): LeaseCapabilityConnection => new LeaseCapabilityConnection($pdo, $database, $prefix, $config)
        );
        $connection = DB::connection('physical_leases');
        $connection->getPdo()->exec('pragma user_version = 100');
        $this->assertSame(100, $connection->maxBindings());

        DB::releaseIdleConnections();

        parallel([static function (): void {
            DB::connection('physical_leases')->getPdo()->exec('pragma user_version = 200');
        }]);

        $this->assertSame(200, $connection->maxBindings());
    }

    public function testSessionConfiguratorsRunOnTheLogicalOwnerAfterEachBorrowerChangesState(): void
    {
        $configurator = new LeaseSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        CoroutineContext::set('__lease_test.account', '1');
        $connection = DB::connection('leases');
        $this->assertSame(1, $connection->selectOne('pragma user_version')->user_version);
        DB::releaseIdleConnections();

        [$other] = parallel([function (): Connection {
            CoroutineContext::set('__lease_test.account', '2');
            $connection = DB::connection('leases');
            $this->assertSame(2, $connection->selectOne('pragma user_version')->user_version);

            return $connection;
        }]);

        $this->assertSame(1, $connection->selectOne('pragma user_version')->user_version);
        $this->assertSame([
            [spl_object_id($connection), '1'],
            [spl_object_id($other), '2'],
            [spl_object_id($connection), '1'],
        ], $configurator->applied);
    }

    #[DataProvider('connectionListenerFailures')]
    public function testConnectionListenerFailuresSettleTheLeaseOnce(bool $reacquire, bool $cancel): void
    {
        $connection = null;

        if ($reacquire) {
            $connection = DB::connection('leases');
            $connection->disconnect();
            DB::releaseIdleConnections();
        }

        $failure = $cancel ? new CanceledException('listener canceled') : new RuntimeException('listener failed');
        $fail = true;
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($failure, &$fail): void {
            if ($event->connection->getName() === 'leases' && $fail) {
                $fail = false;
                throw $failure;
            }
        });

        try {
            if ($reacquire) {
                $connection->getPdo();
            } else {
                DB::connection('leases');
            }

            $this->fail('Expected connection publication to fail.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $this->pool()->getManagedCount());
        $connection ??= DB::connection('leases');
        $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getManagedCount());
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    /**
     * Provide publication failures at initial acquisition and reacquisition.
     */
    public static function connectionListenerFailures(): array
    {
        return [
            'initial failure' => [false, false],
            'initial cancellation' => [false, true],
            'reacquisition failure' => [true, false],
            'reacquisition cancellation' => [true, true],
        ];
    }

    /**
     * Get the pool used by the test's connections.
     */
    protected function pool(): DatabasePool
    {
        return $this->app->make(PoolManager::class)->pool('leases');
    }
}

class LeaseSqliteConnection extends SQLiteConnection
{
}

class LeaseCapabilityConnection extends PostgresConnection
{
    /**
     * Read a session-specific capability from the fixture's SQLite database.
     */
    protected function resolveMaxBindings(): int
    {
        return (int) $this->getPdo()->query('pragma user_version')->fetchColumn();
    }
}

class LeasePdoConnector implements ConnectorInterface
{
    /**
     * Provide independent physical PDOs without an external database server.
     */
    public function connect(array $config): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

class LeaseSessionConfigurator implements SessionConfigurator
{
    public array $applied = [];

    /**
     * Resolve the current borrower's desired session state.
     */
    public function state(PdoConnection $connection): ?string
    {
        return $connection->getName() === 'leases'
            ? CoroutineContext::get('__lease_test.account')
            : null;
    }

    /**
     * Apply a visible SQLite session value and record its logical owner.
     */
    public function apply(PDO $pdo, string $state, PdoConnection $connection): void
    {
        $pdo->exec('pragma user_version = ' . $state);
        $this->applied[] = [spl_object_id($connection), $state];
    }
}
