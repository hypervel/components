<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration;

use Hypervel\Coroutine\Channel;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use RuntimeException;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

/**
 * Tests coroutine safety of database components.
 *
 * These tests verify that Model::unguarded(), DatabaseManager::usingConnection(),
 * and Connection::beforeExecuting() properly isolate state between coroutines.
 *
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class ConnectionCoroutineSafetyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UnguardedTestUser::$eventLog = [];
        Model::reguard();
    }

    public function testUnguardedDisablesGuardingWithinCallback(): void
    {
        $this->assertFalse(Model::isUnguarded());

        Model::unguarded(function () {
            $this->assertTrue(Model::isUnguarded());
        });

        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedRestoresStateAfterException(): void
    {
        $this->assertFalse(Model::isUnguarded());

        try {
            Model::unguarded(function () {
                $this->assertTrue(Model::isUnguarded());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedSupportsNesting(): void
    {
        $this->assertFalse(Model::isUnguarded());

        Model::unguarded(function () {
            $this->assertTrue(Model::isUnguarded());

            Model::unguarded(function () {
                $this->assertTrue(Model::isUnguarded());
            });

            $this->assertTrue(Model::isUnguarded());
        });

        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedIsCoroutineIsolated(): void
    {
        $results = [];

        run(function () use (&$results) {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::unguarded(function () use ($channel) {
                    $channel->push(['coroutine' => 1, 'unguarded' => Model::isUnguarded()]);
                    usleep(50000);
                });
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                usleep(10000);
                $channel->push(['coroutine' => 2, 'unguarded' => Model::isUnguarded()]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['unguarded'];
            }
        });

        $this->assertTrue($results[1], 'Coroutine 1 should be unguarded');
        $this->assertFalse($results[2], 'Coroutine 2 should NOT be unguarded (isolated context)');
    }

    public function testUsingConnectionChangesDefaultWithinCallback(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $manager->usingConnection($testConnection, function () use ($manager, $testConnection) {
            $this->assertSame($testConnection, $manager->getDefaultConnection());
        });

        $this->assertSame($originalDefault, $manager->getDefaultConnection());
    }

    public function testUsingConnectionRestoresStateAfterException(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();
        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        try {
            $manager->usingConnection($testConnection, function () use ($manager, $testConnection) {
                $this->assertSame($testConnection, $manager->getDefaultConnection());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame($originalDefault, $manager->getDefaultConnection());
    }

    public function testUsingConnectionIsCoroutineIsolated(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();
        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $results = [];

        run(function () use ($manager, $testConnection, &$results) {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            $waiter->add(1);
            go(function () use ($channel, $waiter, $manager, $testConnection) {
                $manager->usingConnection($testConnection, function () use ($channel, $manager) {
                    $channel->push(['coroutine' => 1, 'connection' => $manager->getDefaultConnection()]);
                    usleep(50000);
                });
                $waiter->done();
            });

            $waiter->add(1);
            go(function () use ($channel, $waiter, $manager) {
                usleep(10000);
                $channel->push(['coroutine' => 2, 'connection' => $manager->getDefaultConnection()]);
                $waiter->done();
            });

            $waiter->wait();
            $channel->close();

            while (($result = $channel->pop()) !== false) {
                $results[$result['coroutine']] = $result['connection'];
            }
        });

        $this->assertSame($testConnection, $results[1], 'Coroutine 1 should see overridden connection');
        $this->assertSame($originalDefault, $results[2], 'Coroutine 2 should see original connection (isolated)');
    }

    public function testUsingConnectionAffectsDbConnection(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        $connectionBefore = DB::connection();
        $this->assertSame($originalDefault, $connectionBefore->getName());

        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $manager->usingConnection($testConnection, function () use ($testConnection) {
            $connection = DB::connection();
            $this->assertSame(
                $testConnection,
                $connection->getName(),
                'DB::connection() should return the usingConnection override'
            );
        });

        $connectionAfter = DB::connection();
        $this->assertSame($originalDefault, $connectionAfter->getName());
    }

    public function testUsingConnectionAffectsSchemaConnection(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $manager->usingConnection($testConnection, function () use ($testConnection) {
            $schemaBuilder = Schema::connection();
            $connectionName = $schemaBuilder->getConnection()->getName();

            $this->assertSame(
                $testConnection,
                $connectionName,
                'Schema::connection() should return schema builder for usingConnection override'
            );
        });
    }

    public function testUsingConnectionAffectsConnectionResolver(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);

        /** @var ConnectionResolverInterface $resolver */
        $resolver = $this->app->get(ConnectionResolverInterface::class);

        $originalDefault = $manager->getDefaultConnection();
        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $this->assertSame($originalDefault, $resolver->getDefaultConnection());

        $manager->usingConnection($testConnection, function () use ($resolver, $testConnection) {
            $this->assertSame(
                $testConnection,
                $resolver->getDefaultConnection(),
                'ConnectionResolver::getDefaultConnection() should respect usingConnection override'
            );

            $connection = $resolver->connection();
            $this->assertSame(
                $testConnection,
                $connection->getName(),
                'ConnectionResolver::connection() should return usingConnection override'
            );
        });

        $this->assertSame($originalDefault, $resolver->getDefaultConnection());
    }

    public function testBeforeExecutingCallbackIsCalled(): void
    {
        $called = false;
        $capturedQuery = null;

        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());
        $connection->beforeExecuting(function ($query) use (&$called, &$capturedQuery) {
            $called = true;
            $capturedQuery = $query;
        });

        $connection->select('SELECT 1');

        $this->assertTrue($called);
        $this->assertSame('SELECT 1', $capturedQuery);
    }

    public function testClearBeforeExecutingCallbacksExists(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        $called = false;
        $connection->beforeExecuting(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue(method_exists($connection, 'clearBeforeExecutingCallbacks'));

        $connection->clearBeforeExecutingCallbacks();

        $connection->select('SELECT 1');
        $this->assertFalse($called);
    }

    public function testConnectionTracksErrorCount(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        $this->assertTrue(method_exists($connection, 'getErrorCount'));

        $initialCount = $connection->getErrorCount();

        try {
            $connection->select('SELECT * FROM nonexistent_table_xyz');
        } catch (\Throwable) {
            // Expected
        }

        $this->assertGreaterThan($initialCount, $connection->getErrorCount());
    }

    public function testPooledConnectionHasEventDispatcher(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        $dispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($dispatcher, 'Pooled connection should have event dispatcher configured');
    }

    public function testPooledConnectionHasTransactionManager(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        $manager = $connection->getTransactionManager();
        $this->assertNotNull($manager, 'Pooled connection should have transaction manager configured');
    }
}

class UnguardedTestUser extends Model
{
    protected ?string $table = 'tmp_users';

    protected array $fillable = ['name', 'email'];

    public static array $eventLog = [];
}
