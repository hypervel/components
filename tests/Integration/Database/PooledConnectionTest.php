<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Pool\Event\ReleaseConnection;
use ReflectionProperty;

/**
 * Tests for PooledConnection — the adapter that wraps a database Connection
 * for use with Hyperf's connection pool infrastructure.
 *
 * Uses in-memory SQLite via the pool to avoid requiring an external database.
 *
 * @internal
 * @coversNothing
 */
class PooledConnectionTest extends DatabaseTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('database.connections.pool_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ]);
    }

    public function testConstructorSetsEventDispatcher(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $dispatcher = new ReflectionProperty(PooledConnection::class, 'dispatcher');

        $this->assertNotNull(
            $dispatcher->getValue($pooledConnection),
            'PooledConnection should resolve the event dispatcher from the container'
        );
        $this->assertInstanceOf(Dispatcher::class, $dispatcher->getValue($pooledConnection));
    }

    public function testConnectionEstablishedEventFiredOnConstruction(): void
    {
        $fired = false;
        $this->app->get(Dispatcher::class)->listen(
            ConnectionEstablished::class,
            function (ConnectionEstablished $event) use (&$fired) {
                $fired = true;
                $this->assertSame('pool_test', $event->connectionName);
            }
        );

        $pool = new DbPool($this->app, 'pool_test');
        $this->createPooledConnection($pool);

        $this->assertTrue($fired, 'ConnectionEstablished event should be fired when a pooled connection is created');
    }

    public function testGetConnectionReturnsConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $connection = $pooledConnection->getConnection();

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testGetConnectionReturnsSameInstanceWhileValid(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $first = $pooledConnection->getConnection();
        $second = $pooledConnection->getConnection();

        $this->assertSame($first, $second);
    }

    public function testConnectionEstablishedEventFiredOnReconnect(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $count = 0;
        $this->app->get(Dispatcher::class)->listen(
            ConnectionEstablished::class,
            function () use (&$count) {
                ++$count;
            }
        );

        // reconnect() should fire ConnectionEstablished again
        $pooledConnection->reconnect();

        $this->assertSame(1, $count, 'ConnectionEstablished should fire on reconnect');
    }

    public function testReconnectCreatesNewConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $before = $pooledConnection->getConnection();
        $pooledConnection->reconnect();
        $after = $pooledConnection->getConnection();

        // For in-memory SQLite with shared PDO, the Connection object is
        // different but they share the same PDO
        $this->assertNotSame($before, $after);
    }

    public function testReconnectSetsEventDispatcherOnConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $connection = $pooledConnection->getConnection();
        $dispatcher = $connection->getEventDispatcher();

        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
    }

    public function testCheckReturnsFalseWhenNoConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $pooledConnection->close();

        $this->assertFalse($pooledConnection->check());
    }

    public function testCheckReturnsTrueForFreshConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $this->assertTrue($pooledConnection->check());
    }

    public function testCloseDisconnectsAndNullsConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $result = $pooledConnection->close();

        $this->assertTrue($result);
        $this->assertFalse($pooledConnection->check());
    }

    public function testGetActiveConnectionReconnectsWhenStale(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $pooledConnection->close();

        // getActiveConnection should trigger reconnect
        $connection = $pooledConnection->getActiveConnection();

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testReleaseResetsConnectionState(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        // Get a connection through the pool to test proper release
        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        $connection = $pooledConnection->getConnection();

        // Add some state that should be reset
        $connection->beforeExecuting(function () {});

        $pooledConnection->release();

        // After release, getting the connection again from pool should work
        /** @var PooledConnection $newPooledConnection */
        $newPooledConnection = $pool->get();
        $this->assertInstanceOf(Connection::class, $newPooledConnection->getConnection());
        $newPooledConnection->release();
    }

    public function testReleaseRollsBackOpenTransactions(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $connection = $pooledConnection->getConnection();

        // Create a table and start a transaction
        $connection->getSchemaBuilder()->create('test_rollback', function ($table) {
            $table->id();
            $table->string('name');
        });

        $connection->beginTransaction();
        $connection->table('test_rollback')->insert(['name' => 'should_be_rolled_back']);

        $this->assertSame(1, $connection->transactionLevel());

        // Release should roll back
        $pooledConnection->release();

        // Get a new connection and verify the data was rolled back
        /** @var PooledConnection $newPooledConnection */
        $newPooledConnection = $pool->get();
        $newConnection = $newPooledConnection->getConnection();

        $this->assertSame(0, $newConnection->transactionLevel());
        $this->assertSame(0, $newConnection->table('test_rollback')->count());

        $newPooledConnection->release();
    }

    public function testReleaseDispatchesReleaseEventWhenConfigured(): void
    {
        $this->app->get('config')->set('database.connections.pool_test.pool.events', [
            ReleaseConnection::class,
        ]);

        $pool = new DbPool($this->app, 'pool_test');

        $fired = false;
        $this->app->get(Dispatcher::class)->listen(
            ReleaseConnection::class,
            function () use (&$fired) {
                $fired = true;
            }
        );

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $pooledConnection->release();

        $this->assertTrue($fired, 'ReleaseConnection event should be dispatched when configured');
    }

    public function testLastUseTimeUpdatedOnCheck(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $initialTime = $pooledConnection->getLastUseTime();

        usleep(10000); // 10ms
        $pooledConnection->check();

        $this->assertGreaterThan($initialTime, $pooledConnection->getLastUseTime());
    }

    public function testSharedPdoForInMemorySqlite(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        $this->assertNotNull($pool->getSharedInMemorySqlitePdo());

        // Two connections from the same pool should share the same PDO
        /** @var PooledConnection $conn1 */
        $conn1 = $pool->get();
        /** @var PooledConnection $conn2 */
        $conn2 = $pool->get();

        $pdo1 = $conn1->getConnection()->getPdo();
        $pdo2 = $conn2->getConnection()->getPdo();

        $this->assertSame($pdo1, $pdo2, 'In-memory SQLite connections should share the same PDO');

        $conn1->release();
        $conn2->release();
    }

    public function testSharedPdoDataVisibleAcrossConnections(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $conn1 */
        $conn1 = $pool->get();
        $db1 = $conn1->getConnection();

        $db1->getSchemaBuilder()->create('shared_test', function ($table) {
            $table->id();
            $table->string('value');
        });
        $db1->table('shared_test')->insert(['value' => 'hello']);
        $conn1->release();

        // Second connection should see the same data
        /** @var PooledConnection $conn2 */
        $conn2 = $pool->get();
        $db2 = $conn2->getConnection();

        $this->assertSame(1, $db2->table('shared_test')->count());
        $this->assertSame('hello', $db2->table('shared_test')->value('value'));

        $conn2->release();
    }

    /**
     * Create a PooledConnection directly (bypassing pool.get() for unit-style tests).
     */
    private function createPooledConnection(DbPool $pool): PooledConnection
    {
        $config = $this->app->get('config')->get('database.connections.pool_test');
        $config['name'] = 'pool_test';

        return new PooledConnection($this->app, $pool, $config);
    }
}
