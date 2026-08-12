<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use TypeError;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

/**
 * Test shared PDO ownership for pooled in-memory SQLite connections.
 */
class InMemorySqliteSharedPdoTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureInMemoryDatabase();

        // Suppress expected log output from reconnect tests
        $config = $this->app->make('config');
        $config->set('app.stdout_log.level', []);
    }

    protected function configureInMemoryDatabase(): void
    {
        $config = $this->app->make('config');

        $this->app->instance('db.connector.sqlite', new SQLiteConnector);

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
        return $this->app->make(PoolFactory::class);
    }

    #[DataProvider('inMemoryDatabaseProvider')]
    public function testPoolCapacityFollowsSQLiteClassification(string $database, bool $inMemory): void
    {
        $config = $this->app->make('config');

        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
            ],
        ];

        $configKey = 'in_memory_test_' . hash('xxh128', $database);
        $config->set("database.connections.{$configKey}", $connectionConfig);

        $factory = $this->getPoolFactory();
        $pool = $factory->getPool($configKey);

        $this->assertSame($inMemory ? 1 : 2, $pool->getOption()->getMaxConnections());
        $this->assertSame($inMemory, $pool->getSharedInMemorySqlitePdo() instanceof PDO);
        $factory->flushPool($configKey);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function inMemoryDatabaseProvider(): array
    {
        return [
            'standard :memory:' => [':memory:', true],
            'memory URI path' => ['file::memory:', true],
            'encoded memory URI path' => ['file:%3Amemory%3A', true],
            'empty URI path in memory mode' => ['file:?mode=memory', true],
            'named URI in memory mode' => ['file:test?mode=memory', true],
            'encoded memory mode' => ['file:test?mode=%6demory', true],
            'regular file path' => ['/tmp/database.sqlite', false],
            'file URI' => ['file:/tmp/database.sqlite', false],
            'uppercase mode key' => ['file:test?MODE=memory', false],
            'last duplicate mode wins' => ['file:test?mode=memory&mode=rwc', false],
        ];
    }

    public function testNonSqliteDriverIsNotInMemorySqlite(): void
    {
        $config = $this->app->make('config');

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

        $this->assertSame(2, $pool->getOption()->getMaxConnections());
        $this->assertNull($pool->getSharedInMemorySqlitePdo());

        $factory->flushPool('mysql_memory_test');
    }

    public function testDerivedReadPoolRejectsUriInMemoryDatabase(): void
    {
        $config = $this->app->make('config');
        $config->set('database.connections.uri_read_memory_test', [
            'driver' => 'sqlite',
            'database' => '/tmp/database.sqlite',
            'prefix' => '',
            'read' => [
                'database' => 'file::memory:',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Database connection [uri_read_memory_test::read] cannot use a derived read pool for in-memory SQLite.'
        );

        $this->getPoolFactory()->getPool('uri_read_memory_test::read');
    }

    public function testInMemoryPoolPreservesAZeroManagedConnectionFloor(): void
    {
        $config = $this->app->make('config');
        $config->set('database.connections.zero_floor_memory_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => [
                'min_connections' => 0,
                'max_connections' => 5,
            ],
        ]);

        $pool = $this->getPoolFactory()->getPool('zero_floor_memory_test');

        $this->assertSame(0, $pool->getOption()->getMinConnections());
        $this->assertSame(1, $pool->getOption()->getMaxConnections());
    }

    /**
     * @param array<string, mixed> $poolOptions
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidPoolOptionProvider')]
    public function testInMemoryPoolDoesNotMaskInvalidConnectionCounts(
        array $poolOptions,
        string $exception
    ): void {
        $config = $this->app->make('config');
        $connection = 'invalid_memory_test_' . hash('xxh128', serialize($poolOptions));
        $config->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => $poolOptions,
        ]);

        $this->expectException($exception);

        $this->getPoolFactory()->getPool($connection);
    }

    /**
     * @return array<string, array{array<string, mixed>, class-string<Throwable>}>
     */
    public static function invalidPoolOptionProvider(): array
    {
        return [
            'negative minimum' => [
                ['min_connections' => -1, 'max_connections' => 5],
                InvalidArgumentException::class,
            ],
            'zero maximum' => [
                ['min_connections' => 0, 'max_connections' => 0],
                InvalidArgumentException::class,
            ],
            'minimum exceeds maximum' => [
                ['min_connections' => 2, 'max_connections' => 1],
                InvalidArgumentException::class,
            ],
            'non-integer minimum' => [
                ['min_connections' => '1', 'max_connections' => 5],
                TypeError::class,
            ],
        ];
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
        $config = $this->app->make('config');

        $tempDirectory = ParallelTesting::tempDir('InMemorySqliteSharedPdoTest');
        $files = new Filesystem;
        $files->deleteDirectory($tempDirectory);
        $files->ensureDirectoryExists($tempDirectory);
        $tempFile = $tempDirectory . '/database.sqlite';
        touch($tempFile);

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
            (new Filesystem)->deleteDirectory($tempDirectory);
        }
    }

    public function testInMemorySqlitePoolSerializesOneSharedPdoOwner(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool): void {
            $secondAttempted = new Channel(1);
            $secondAcquired = new Channel(1);

            [$firstPdo, $secondPdo] = parallel([
                function () use ($pool, $secondAttempted, $secondAcquired): PDO {
                    $pooledConnection = $pool->get();
                    $pdo = $pooledConnection->getConnection()->getPdo();

                    $secondAttempted->pop();
                    $this->assertFalse($secondAcquired->pop(0.01));
                    $pooledConnection->release();

                    return $pdo;
                },
                function () use ($pool, $secondAttempted, $secondAcquired): PDO {
                    $secondAttempted->push(true);
                    $pooledConnection = $pool->get();
                    $secondAcquired->push(true);

                    try {
                        return $pooledConnection->getConnection()->getPdo();
                    } finally {
                        $pooledConnection->release();
                    }
                },
            ]);

            $this->assertSame(1, $pool->getOption()->getMaxConnections());
            $this->assertSame($firstPdo, $secondPdo);
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

    public function testCloseClearsSharedPdo(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        // Verify shared PDO exists
        $this->assertInstanceOf(PDO::class, $pool->getSharedInMemorySqlitePdo());

        $pool->close();

        // Shared PDO should be cleared
        $this->assertNull($pool->getSharedInMemorySqlitePdo());
    }

    // =========================================================================
    // ConnectionFactory::makeSqliteFromSharedPdo() tests
    // =========================================================================

    public function testMakeSqliteFromSharedPdoCreatesConnectionWithProvidedPdo(): void
    {
        $factory = $this->app->make(ConnectionFactory::class);

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
        $factory = $this->app->make(ConnectionFactory::class);

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
            $pooled->release();

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

    public function testPooledConnectionRefreshCleansUpSharedPdoTransaction(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool): void {
            $sharedPdo = $pool->getSharedInMemorySqlitePdo();
            $pooled = $pool->get();
            $connection = $pooled->getConnection();
            $rolledBack = false;

            try {
                $connection->statement('CREATE TABLE reconnect_transaction_test (id INTEGER PRIMARY KEY, value TEXT)');
                $connection->statement("INSERT INTO reconnect_transaction_test VALUES (1, 'committed')");
                $connection->beginTransaction();
                $connection->afterRollBack(function () use (&$rolledBack): void {
                    $rolledBack = true;
                });
                $connection->statement("INSERT INTO reconnect_transaction_test VALUES (2, 'uncommitted')");

                $this->assertSame(1, $connection->transactionLevel());
                $this->assertTrue($sharedPdo->inTransaction());

                $connection->reconnect();

                $this->assertSame($sharedPdo, $connection->getPdo());
                $this->assertSame(0, $connection->transactionLevel());
                $this->assertFalse($sharedPdo->inTransaction());
                $this->assertTrue($rolledBack);
                $this->assertSame(
                    'committed',
                    $connection->selectOne('SELECT value FROM reconnect_transaction_test WHERE id = 1')->value
                );
                $this->assertNull(
                    $connection->selectOne('SELECT value FROM reconnect_transaction_test WHERE id = 2')
                );

                $connection->transaction(function (Connection $connection): void {
                    $connection->statement("INSERT INTO reconnect_transaction_test VALUES (3, 'after reconnect')");
                });

                $this->assertSame(
                    'after reconnect',
                    $connection->selectOne('SELECT value FROM reconnect_transaction_test WHERE id = 3')->value
                );
            } finally {
                if ($sharedPdo->inTransaction()) {
                    $sharedPdo->rollBack();
                }

                $pooled->release();
            }
        });
    }

    public function testPooledConnectionRefreshRebindsSharedPdoAfterRollbackCallbackFailure(): void
    {
        $factory = $this->getPoolFactory();
        $pool = $factory->getPool('memory_test');

        run(function () use ($pool): void {
            $sharedPdo = $pool->getSharedInMemorySqlitePdo();
            $pooled = $pool->get();
            $connection = $pooled->getConnection();
            $failure = new RuntimeException('rollback callback failure');

            try {
                $connection->statement('CREATE TABLE reconnect_callback_test (id INTEGER PRIMARY KEY, value TEXT)');
                $connection->beginTransaction();
                $connection->afterRollBack(static function () use ($failure): never {
                    throw $failure;
                });
                $connection->statement("INSERT INTO reconnect_callback_test VALUES (1, 'uncommitted')");

                try {
                    $connection->reconnect();
                    $this->fail('Expected the rollback callback to fail.');
                } catch (RuntimeException $exception) {
                    $this->assertSame($failure, $exception);
                }

                $this->assertSame($sharedPdo, $connection->getRawPdo());
                $this->assertSame($sharedPdo, $connection->getRawReadPdo());
                $this->assertSame(0, $connection->transactionLevel());
                $this->assertFalse($sharedPdo->inTransaction());
                $this->assertNull(
                    $connection->selectOne('SELECT value FROM reconnect_callback_test WHERE id = 1')
                );

                $connection->transaction(function (Connection $connection): void {
                    $connection->statement("INSERT INTO reconnect_callback_test VALUES (2, 'after reconnect')");
                });

                $this->assertSame(
                    'after reconnect',
                    $connection->selectOne('SELECT value FROM reconnect_callback_test WHERE id = 2')->value
                );
            } finally {
                if ($sharedPdo->inTransaction()) {
                    $sharedPdo->rollBack();
                }

                $pooled->release();
            }
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
        $capsule = new \Hypervel\Database\Capsule\Manager;
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
        $capsule1 = new \Hypervel\Database\Capsule\Manager;
        $capsule1->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $connection1 = $capsule1->getConnection();
        $connection1->statement('CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT)');
        $connection1->statement("INSERT INTO test_table (value) VALUES ('capsule1_data')");

        // Create second Capsule - should be completely isolated
        $capsule2 = new \Hypervel\Database\Capsule\Manager;
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
        $capsule1 = new \Hypervel\Database\Capsule\Manager;
        $capsule1->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $capsule2 = new \Hypervel\Database\Capsule\Manager;
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
