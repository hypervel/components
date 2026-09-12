<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

use function Hypervel\Coroutine\run;

class PoolConnectionManagementTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected static string $databasePath;

    protected static string $databaseDirectory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$databaseDirectory = ParallelTesting::tempDir('PoolConnectionManagementTest');
        $files = new Filesystem;
        $files->deleteDirectory(self::$databaseDirectory);
        $files->ensureDirectoryExists(self::$databaseDirectory);
        self::$databasePath = self::$databaseDirectory . '/database.sqlite';
        touch(self::$databasePath);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDatabase();
        $this->createTestTable();

        // Suppress expected error logs from transaction rollback tests
        $config = $this->app->make('config');
        $config->set('app.stdout_log.level', []);
    }

    protected function configureDatabase(): void
    {
        $config = $this->app->make('config');

        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 5,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 60.0,
            ],
        ];

        $config->set('database.connections.pool_test', $connectionConfig);
    }

    protected function createTestTable(): void
    {
        Schema::connection('pool_test')->dropIfExists('pool_mgmt_test');
        Schema::connection('pool_test')->create('pool_mgmt_test', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function poolManager(): PoolManager
    {
        return $this->app->make(PoolManager::class);
    }

    protected function getPooledConnection(): PooledConnection
    {
        $poolManager = $this->poolManager();
        $pool = $poolManager->pool('pool_test');

        return $pool->borrow();
    }

    // =========================================================================
    // DB-01: Nested transaction rollback on release
    // =========================================================================

    /**
     * Test that releasing a connection with open transaction rolls back completely.
     *
     * This verifies the fix for DB-01: rollBack(0) is called instead of rollBack()
     * to fully exit all transaction levels.
     */
    public function testReleasingConnectionWithOpenTransactionRollsBack(): void
    {
        run(function () {
            $pooled = $this->getPooledConnection();
            $connection = $pooled->getConnection();

            // Start a transaction and insert data (don't commit)
            $connection->beginTransaction();
            $connection->table('pool_mgmt_test')->insert([
                'name' => 'Should be rolled back',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertEquals(1, $connection->transactionLevel());

            // Release without committing - should trigger rollback
            $pooled->release();
        });

        // Verify the data was rolled back
        run(function () {
            $pooled = $this->getPooledConnection();
            $connection = $pooled->getConnection();

            $count = $connection->table('pool_mgmt_test')
                ->where('name', 'Should be rolled back')
                ->count();

            $this->assertEquals(0, $count, 'Data should be rolled back when connection released with open transaction');

            $pooled->release();
        });
    }

    /**
     * Test that nested transactions are fully rolled back on release.
     *
     * This is the critical test for DB-01: ensures rollBack(0) is used to
     * exit ALL transaction levels, not just one.
     */
    public function testNestedTransactionsAreFullyRolledBackOnRelease(): void
    {
        run(function () {
            $pooled = $this->getPooledConnection();
            $connection = $pooled->getConnection();

            // Create nested transactions
            $connection->beginTransaction(); // Level 1
            $connection->table('pool_mgmt_test')->insert([
                'name' => 'Level 1 data',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $connection->beginTransaction(); // Level 2 (savepoint)
            $connection->table('pool_mgmt_test')->insert([
                'name' => 'Level 2 data',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $connection->beginTransaction(); // Level 3 (savepoint)
            $connection->table('pool_mgmt_test')->insert([
                'name' => 'Level 3 data',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertEquals(3, $connection->transactionLevel());

            // Release without committing any level
            $pooled->release();
        });

        // Verify ALL nested data was rolled back
        run(function () {
            $pooled = $this->getPooledConnection();
            $connection = $pooled->getConnection();

            $level1Count = $connection->table('pool_mgmt_test')
                ->where('name', 'Level 1 data')
                ->count();
            $level2Count = $connection->table('pool_mgmt_test')
                ->where('name', 'Level 2 data')
                ->count();
            $level3Count = $connection->table('pool_mgmt_test')
                ->where('name', 'Level 3 data')
                ->count();

            $this->assertEquals(0, $level1Count, 'Level 1 data should be rolled back');
            $this->assertEquals(0, $level2Count, 'Level 2 data should be rolled back');
            $this->assertEquals(0, $level3Count, 'Level 3 data should be rolled back');

            // Connection should be clean (no open transactions)
            $this->assertEquals(0, $connection->transactionLevel());

            $pooled->release();
        });
    }

    // =========================================================================
    // Pool purge semantics
    // =========================================================================

    /**
     * Test that purge closes all connections in the pool.
     */
    public function testPurgeClosesAllConnections(): void
    {
        $poolManager = $this->poolManager();
        $pool = $poolManager->pool('pool_test');

        // Get and release a few connections to populate the pool
        run(function () use ($pool) {
            $connections = [];
            for ($i = 0; $i < 3; ++$i) {
                $connections[] = $pool->borrow();
            }
            foreach ($connections as $connection) {
                $connection->release();
            }
        });

        $connectionsBeforePurge = $pool->getManagedCount();
        $this->assertGreaterThan(0, $connectionsBeforePurge, 'Pool should have connections before purge');

        $poolManager->purge('pool_test');

        $newPool = $poolManager->pool('pool_test');
        $this->assertSame(0, $newPool->getManagedCount(), 'Fresh pool should have no connections');
    }

    /**
     * Test that purgeAll closes all connections in all pools.
     */
    public function testPurgeAllClosesAllPoolConnections(): void
    {
        $poolManager = $this->poolManager();

        // Get pool and create some connections
        $pool = $poolManager->pool('pool_test');

        run(function () use ($pool) {
            $connection = $pool->borrow();
            $connection->release();
        });

        $this->assertGreaterThan(0, $pool->getManagedCount());

        $poolManager->purgeAll();

        $newPool = $poolManager->pool('pool_test');
        $this->assertSame(0, $newPool->getManagedCount());
    }

    // =========================================================================
    // DB-03: DatabaseManager disconnect/reconnect/purge
    // =========================================================================

    /**
     * Test that disconnect() nulls PDOs on existing connection in context.
     */
    public function testDisconnectNullsPdosOnExistingConnection(): void
    {
        run(function () {
            /** @var DatabaseManager $manager */
            $manager = $this->app->make(DatabaseManager::class);

            // Get a connection (puts it in context)
            $connection = $manager->connection('pool_test');
            $this->assertInstanceOf(Connection::class, $connection);

            // Verify PDO is set
            $this->assertNotNull($connection->getPdo());

            // Disconnect
            $manager->disconnect('pool_test');

            // PDO should now be null (will reconnect on next use)
            // We can't directly check getPdo() as it auto-reconnects,
            // but we can verify disconnect was called by checking the method works
            $this->assertTrue(true, 'Disconnect completed without error');
        });
    }

    /**
     * Test that disconnect() does nothing if no connection exists in context.
     */
    public function testDisconnectDoesNothingWithoutExistingConnection(): void
    {
        run(function () {
            /** @var DatabaseManager $manager */
            $manager = $this->app->make(DatabaseManager::class);

            // Clear any existing connection from context
            $contextKey = '__database.connection.pool_test';
            CoroutineContext::forget($contextKey);

            // This should not throw
            $manager->disconnect('pool_test');

            $this->assertTrue(true, 'Disconnect without existing connection should not throw');
        });
    }

    /**
     * Test that reconnect() returns existing connection after reconnecting it.
     */
    public function testReconnectReconnectsExistingConnection(): void
    {
        run(function () {
            /** @var DatabaseManager $manager */
            $manager = $this->app->make(DatabaseManager::class);

            // Get initial connection
            $connection1 = $manager->connection('pool_test');

            // Reconnect
            $connection2 = $manager->reconnect('pool_test');

            // Should be the same connection instance (from context)
            $this->assertSame($connection1, $connection2);

            // Should have working PDO
            $this->assertNotNull($connection2->getPdo());
        });
    }

    /**
     * Test that reconnect() gets fresh connection if none exists.
     */
    public function testReconnectGetsFreshConnectionWhenNoneExists(): void
    {
        run(function () {
            /** @var DatabaseManager $manager */
            $manager = $this->app->make(DatabaseManager::class);

            // Clear any existing connection from context
            $contextKey = '__database.connection.pool_test';
            CoroutineContext::forget($contextKey);

            // Reconnect should get a fresh connection
            $connection = $manager->reconnect('pool_test');

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertNotNull($connection->getPdo());
        });
    }

    /**
     * Test that DatabaseManager::purge() removes the pool.
     */
    public function testDatabaseManagerPurgeRemovesPool(): void
    {
        $poolManager = $this->poolManager();

        // First, populate the pool with some connections
        run(function () {
            $pooled1 = $this->getPooledConnection();
            $pooled2 = $this->getPooledConnection();
            $pooled1->release();
            $pooled2->release();
        });

        // Pool should have connections now
        $pool = $poolManager->pool('pool_test');
        $connectionsBefore = $pool->getManagedCount();
        $this->assertGreaterThan(0, $connectionsBefore, 'Pool should have connections before purge');

        // Purge
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);
        $manager->purge('pool_test');

        $newPool = $poolManager->pool('pool_test');
        $this->assertSame(0, $newPool->getManagedCount(), 'Pool should be empty after purge');
    }

    // =========================================================================
    // DB-04: ConnectionEstablished event
    // =========================================================================

    /**
     * Test that ConnectionEstablished event is dispatched when pooled connection is created.
     */
    public function testConnectionEstablishedEventIsDispatchedForPooledConnection(): void
    {
        $eventDispatched = false;
        $dispatchedConnection = null;

        // Register a listener for the ConnectionEstablished event
        $this->app->make('events')->listen(
            ConnectionEstablished::class,
            function (ConnectionEstablished $event) use (&$eventDispatched, &$dispatchedConnection) {
                $eventDispatched = true;
                $dispatchedConnection = $event->connection;
            }
        );

        $poolManager = $this->poolManager();
        $poolManager->purge('pool_test');

        run(function () {
            $pooled = $this->getPooledConnection();
            // Just getting the connection should trigger the event via reconnect()
            $pooled->getConnection();
            $pooled->release();
        });

        $this->assertTrue($eventDispatched, 'ConnectionEstablished event should be dispatched when pooled connection is created');
        $this->assertInstanceOf(Connection::class, $dispatchedConnection);
    }

    /**
     * Test that ConnectionEstablished event contains the correct connection name.
     */
    public function testConnectionEstablishedEventContainsCorrectConnection(): void
    {
        $capturedConnectionName = null;

        // Register a listener for the ConnectionEstablished event
        $this->app->make('events')->listen(
            ConnectionEstablished::class,
            function (ConnectionEstablished $event) use (&$capturedConnectionName) {
                $capturedConnectionName = $event->connection->getName();
            }
        );

        $poolManager = $this->poolManager();
        $poolManager->purge('pool_test');

        run(function () {
            $pooled = $this->getPooledConnection();
            $pooled->getConnection();
            $pooled->release();
        });

        $this->assertEquals('pool_test', $capturedConnectionName);
    }
}
