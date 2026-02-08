<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Config\Repository;
use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Testbench\TestCase;
use PDO;
use ReflectionMethod;

use function Hypervel\Coroutine\run;

/**
 * Tests for in-memory SQLite shared PDO functionality.
 *
 * Verifies that:
 * - DbPool correctly detects in-memory SQLite databases
 * - All pool slots share the same PDO for in-memory SQLite
 * - ConnectionFactory::makeSqliteFromSharedPdo() works correctly
 * - PooledConnection handles shared PDO in reconnect/close/refresh
 *
 * @internal
 * @coversNothing
 */
class InMemorySqliteSharedPdoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureInMemoryDatabase();

        // Suppress expected log output from reconnect tests
        $config = $this->app->get(Repository::class);
        $config->set(StdoutLoggerInterface::class . '.log_level', []);
    }

    protected function configureInMemoryDatabase(): void
    {
        $config = $this->app->get(Repository::class);

        $this->app->set('db.connector.sqlite', new SQLiteConnector());

        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 5,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ];

        $config->set('database.connections.memory_test', $connectionConfig);
    }

    protected function getPoolFactory(): PoolFactory
    {
        return $this->app->get(PoolFactory::class);
    }

    // =========================================================================
    // DbPool::isInMemorySqlite() detection tests
    // =========================================================================

    /**
     * @dataProvider inMemoryDatabaseProvider
     */
    public function testIsInMemorySqliteDetection(string $database, bool $expected): void
    {
        $config = $this->app->get(Repository::class);

        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
            ],
        ];

        $configKey = 'in_memory_test_' . md5($database);
        $config->set("database.connections.{$configKey}", $connectionConfig);

        $factory = $this->getPoolFactory();
        $pool = $factory->getPool($configKey);

        // Use reflection to test the protected method
        $method = new ReflectionMethod(DbPool::class, 'isInMemorySqlite');

        $this->assertSame($expected, $method->invoke($pool));

        // Cleanup
        $factory->flushPool($configKey);
    }

    public static function inMemoryDatabaseProvider(): array
    {
        return [
            'standard :memory:' => [':memory:', true],
            'query string mode=memory' => ['file:test?mode=memory', true],
            'ampersand mode=memory' => ['file:test?cache=shared&mode=memory', true],
            'mode=memory at end' => ['file:test?other=value&mode=memory', true],
            'regular file path' => ['/tmp/database.sqlite', false],
            'relative path' => ['database.sqlite', false],
            'empty string' => ['', false],
            'memory in path name' => ['/tmp/memory.sqlite', false],
            'mode_memory without equals' => ['file:test?mode_memory', false],
        ];
    }

    public function testNonSqliteDriverIsNotInMemorySqlite(): void
    {
        $config = $this->app->get(Repository::class);

        $connectionConfig = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => ':memory:', // Even with :memory: database name
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
            ],
        ];

        $config->set('database.connections.mysql_memory_test', $connectionConfig);

        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('mysql_memory_test');

        $method = new ReflectionMethod(DbPool::class, 'isInMemorySqlite');

        $this->assertFalse($method->invoke($pool));

        $factory->flushPool('mysql_memory_test');
    }

    // =========================================================================
    // Shared PDO tests
    // =========================================================================

    public function testInMemorySqlitePoolHasSharedPdo(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        $sharedPdo = $pool->getSharedInMemorySqlitePdo();

        $this->assertInstanceOf(PDO::class, $sharedPdo);
    }

    public function testFileSqlitePoolDoesNotHaveSharedPdo(): void
    {
        $config = $this->app->get(Repository::class);

        $tempFile = sys_get_temp_dir() . '/test_no_shared_pdo.db';
        @touch($tempFile);

        try {
            $connectionConfig = [
                'driver' => 'sqlite',
                'database' => $tempFile,
                'prefix' => '',
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 2,
                ],
            ];

            $config->set('database.connections.file_sqlite_test', $connectionConfig);

            $factory = $this->getPoolFactory();
            $pool = $factory->getPool('file_sqlite_test');

            $this->assertNull($pool->getSharedInMemorySqlitePdo());

            $factory->flushPool('file_sqlite_test');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testAllPoolSlotsShareSamePdoForInMemorySqlite(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool) {
            // Get multiple pooled connections
            $pooled1 = $pool->get();
            $pooled2 = $pool->get();

            $connection1 = $pooled1->getConnection();
            $connection2 = $pooled2->getConnection();

            // Both connections should have the same underlying PDO
            $pdo1 = $connection1->getPdo();
            $pdo2 = $connection2->getPdo();

            $this->assertSame($pdo1, $pdo2, 'All pool slots should share the same PDO for in-memory SQLite');

            $pooled1->release();
            $pooled2->release();
        });
    }

    public function testSharedPdoMaintainsDataAcrossPoolSlots(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool) {
            // Create table and insert data using first connection
            $pooled1 = $pool->get();
            $connection1 = $pooled1->getConnection();

            $connection1->statement('CREATE TABLE IF NOT EXISTS shared_test (id INTEGER PRIMARY KEY, name TEXT)');
            $connection1->statement("INSERT INTO shared_test (name) VALUES ('test_value')");

            $pooled1->release();

            // Verify data is visible from second connection
            $pooled2 = $pool->get();
            $connection2 = $pooled2->getConnection();

            $result = $connection2->selectOne('SELECT name FROM shared_test WHERE id = 1');

            $this->assertNotNull($result);
            $this->assertEquals('test_value', $result->name);

            $pooled2->release();
        });
    }

    public function testFlushAllClearsSharedPdo(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        // Verify shared PDO exists
        $this->assertInstanceOf(PDO::class, $pool->getSharedInMemorySqlitePdo());

        // Flush the pool
        $pool->flushAll();

        // Shared PDO should be cleared
        $this->assertNull($pool->getSharedInMemorySqlitePdo());
    }

    // =========================================================================
    // ConnectionFactory::makeSqliteFromSharedPdo() tests
    // =========================================================================

    public function testMakeSqliteFromSharedPdoCreatesConnectionWithProvidedPdo(): void
    {
        $factory = $this->app->get(ConnectionFactory::class);

        // Create a PDO manually
        $pdo = new PDO('sqlite::memory:');

        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'test_',
        ];

        $connection = $factory->makeSqliteFromSharedPdo($pdo, $config, 'test_connection');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($pdo, $connection->getPdo());
        $this->assertEquals('test_', $connection->getTablePrefix());
        $this->assertEquals('test_connection', $connection->getName());
    }

    public function testMakeSqliteFromSharedPdoUsesWriteConfigWhenReadWritePresent(): void
    {
        $factory = $this->app->get(ConnectionFactory::class);

        $pdo = new PDO('sqlite::memory:');

        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'read' => [
                'prefix' => 'read_',
            ],
            'write' => [
                'prefix' => 'write_',
            ],
        ];

        $connection = $factory->makeSqliteFromSharedPdo($pdo, $config, 'rw_test');

        // Should use write config's prefix
        $this->assertEquals('write_', $connection->getTablePrefix());
    }

    // =========================================================================
    // PooledConnection behavior with shared PDO
    // =========================================================================

    public function testPooledConnectionCloseDoesNotDisconnectSharedPdo(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool) {
            $sharedPdo = $pool->getSharedInMemorySqlitePdo();

            // Create table using the shared PDO directly
            $sharedPdo->exec('CREATE TABLE IF NOT EXISTS close_test (id INTEGER PRIMARY KEY)');
            $sharedPdo->exec('INSERT INTO close_test (id) VALUES (1)');

            // Get a pooled connection
            $pooled = $pool->get();
            $connection = $pooled->getConnection();

            // Verify we can see the data
            $result = $connection->selectOne('SELECT id FROM close_test WHERE id = 1');
            $this->assertNotNull($result);

            // Close the pooled connection (should NOT disconnect the shared PDO)
            $pooled->close();

            // The shared PDO should still be functional
            // Get another pooled connection and verify data still exists
            $pooled2 = $pool->get();
            $connection2 = $pooled2->getConnection();

            $result2 = $connection2->selectOne('SELECT id FROM close_test WHERE id = 1');
            $this->assertNotNull($result2, 'Data should still exist because shared PDO was not disconnected');

            $pooled2->release();
        });
    }

    public function testPooledConnectionRefreshRebindsToSharedPdo(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool) {
            $sharedPdo = $pool->getSharedInMemorySqlitePdo();

            // Create table and data
            $sharedPdo->exec('CREATE TABLE IF NOT EXISTS refresh_test (id INTEGER PRIMARY KEY, value TEXT)');
            $sharedPdo->exec("INSERT INTO refresh_test (id, value) VALUES (1, 'original')");

            $pooled = $pool->get();
            $connection = $pooled->getConnection();

            // Trigger a refresh via the reconnector
            // The refresh() method should rebind to the same shared PDO, not create a fresh one
            $connection->reconnect();

            // After refresh, we should still see the same data (same PDO)
            $result = $connection->selectOne('SELECT value FROM refresh_test WHERE id = 1');
            $this->assertNotNull($result);
            $this->assertEquals('original', $result->value);

            // Verify PDO is still the shared one
            $this->assertSame($sharedPdo, $connection->getPdo());

            $pooled->release();
        });
    }

    public function testReconnectUsesSharedPdoForInMemorySqlite(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool) {
            $sharedPdo = $pool->getSharedInMemorySqlitePdo();

            $pooled = $pool->get();
            $connection = $pooled->getConnection();

            // Connection should be using the shared PDO
            $this->assertSame($sharedPdo, $connection->getPdo());

            $pooled->release();
        });
    }

    // =========================================================================
    // Capsule isolation tests - verifies Capsule does NOT use shared PDO
    // =========================================================================

    public function testCapsuleConnectionsAreIsolatedFromPooledConnections(): void
    {
        // First, create data via pooled connection
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool) {
            $pooled = $pool->get();
            $connection = $pooled->getConnection();

            $connection->statement('CREATE TABLE IF NOT EXISTS capsule_isolation_test (id INTEGER PRIMARY KEY, source TEXT)');
            $connection->statement("INSERT INTO capsule_isolation_test (source) VALUES ('pooled')");

            $pooled->release();
        });

        // Now create a Capsule instance - it should have its own isolated database
        $capsule = new \Hypervel\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $capsuleConnection = $capsule->getConnection();

        // Capsule should NOT see the data from pooled connection (different PDO)
        // This query should fail because the table doesn't exist in Capsule's database
        $tables = $capsuleConnection->select("SELECT name FROM sqlite_master WHERE type='table' AND name='capsule_isolation_test'");

        $this->assertEmpty($tables, 'Capsule should have its own isolated in-memory database, not sharing with pool');
    }

    public function testMultipleCapsuleInstancesAreIsolatedFromEachOther(): void
    {
        // Create first Capsule and add data
        $capsule1 = new \Hypervel\Database\Capsule\Manager();
        $capsule1->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $connection1 = $capsule1->getConnection();
        $connection1->statement('CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT)');
        $connection1->statement("INSERT INTO test_table (value) VALUES ('capsule1_data')");

        // Create second Capsule - should be completely isolated
        $capsule2 = new \Hypervel\Database\Capsule\Manager();
        $capsule2->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $connection2 = $capsule2->getConnection();

        // Capsule2 should NOT see the table from Capsule1
        $tables = $connection2->select("SELECT name FROM sqlite_master WHERE type='table' AND name='test_table'");

        $this->assertEmpty($tables, 'Each Capsule instance should have its own isolated in-memory database');

        // Verify Capsule1 still has its data
        $result = $connection1->selectOne('SELECT value FROM test_table WHERE id = 1');
        $this->assertEquals('capsule1_data', $result->value);
    }

    public function testCapsuleConnectionsGetFreshPdoEachTime(): void
    {
        $capsule1 = new \Hypervel\Database\Capsule\Manager();
        $capsule1->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $capsule2 = new \Hypervel\Database\Capsule\Manager();
        $capsule2->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $pdo1 = $capsule1->getConnection()->getPdo();
        $pdo2 = $capsule2->getConnection()->getPdo();

        // Each Capsule should have a different PDO instance
        $this->assertNotSame($pdo1, $pdo2, 'Each Capsule instance should have its own PDO');
    }
}
