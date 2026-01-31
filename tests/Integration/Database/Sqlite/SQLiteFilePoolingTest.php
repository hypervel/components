<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

/**
 * Tests that file-based SQLite works correctly with connection pooling.
 *
 * Unlike :memory: databases which need special handling (each PDO gets a separate
 * in-memory database), file-based SQLite naturally works with pooling because all
 * pooled connections point to the same file on disk.
 *
 * These tests use coroutines to verify that different pooled connections can see
 * each other's data - proving they all access the same underlying file.
 *
 * @internal
 * @coversNothing
 */
class SQLiteFilePoolingTest extends TestCase
{
    protected static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$databasePath = sys_get_temp_dir() . '/hypervel_sqlite_pool_test.db';

        // Ensure clean state
        if (file_exists(self::$databasePath)) {
            @unlink(self::$databasePath);
        }
        touch(self::$databasePath);
    }

    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$databasePath)) {
            @unlink(self::$databasePath);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDatabase();
        $this->createTestTable();
    }

    protected function configureDatabase(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $this->app->set('db.connector.sqlite', new SQLiteConnector());

        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => self::$databasePath,
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

        $config->set('database.connections.sqlite_file', $connectionConfig);
    }

    protected function createTestTable(): void
    {
        Schema::connection('sqlite_file')->dropIfExists('pool_test_items');
        Schema::connection('sqlite_file')->create('pool_test_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->integer('value')->default(0);
            $table->timestamps();
        });
    }

    protected function getPooledConnection(): PooledConnection
    {
        $factory = $this->app->get(PoolFactory::class);
        $pool = $factory->getPool('sqlite_file');

        return $pool->get();
    }

    /**
     * Test that data written by one pooled connection is visible to another.
     *
     * This verifies that file-based SQLite pooling works correctly - all connections
     * share the same underlying file.
     */
    public function testDataWrittenByOneConnectionIsVisibleToAnother(): void
    {
        $dataVisibleInCoroutine2 = null;

        run(function () use (&$dataVisibleInCoroutine2) {
            // Coroutine 1: Write data
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->table('pool_test_items')->insert([
                'name' => 'Written by connection 1',
                'value' => 42,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pooled1->release();
            usleep(1000);

            // Coroutine 2: Read data written by coroutine 1
            go(function () use (&$dataVisibleInCoroutine2) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $item = $connection2->table('pool_test_items')
                    ->where('name', 'Written by connection 1')
                    ->first();

                $dataVisibleInCoroutine2 = $item;

                $pooled2->release();
            });
        });

        $this->assertNotNull(
            $dataVisibleInCoroutine2,
            'Data written by one pooled connection should be visible to another'
        );
        $this->assertEquals(42, $dataVisibleInCoroutine2->value);
    }

    /**
     * Test that updates from one connection are visible to another.
     */
    public function testUpdatesAreVisibleAcrossConnections(): void
    {
        $updatedValueInCoroutine2 = null;

        run(function () use (&$updatedValueInCoroutine2) {
            // Setup: Insert initial data
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->table('pool_test_items')->insert([
                'name' => 'Update test item',
                'value' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update the value
            $connection1->table('pool_test_items')
                ->where('name', 'Update test item')
                ->update(['value' => 999]);

            $pooled1->release();
            usleep(1000);

            // Coroutine 2: Read the updated value
            go(function () use (&$updatedValueInCoroutine2) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $item = $connection2->table('pool_test_items')
                    ->where('name', 'Update test item')
                    ->first();

                $updatedValueInCoroutine2 = $item?->value;

                $pooled2->release();
            });
        });

        $this->assertEquals(
            999,
            $updatedValueInCoroutine2,
            'Updated value should be visible to another pooled connection'
        );
    }

    /**
     * Test that deletes from one connection affect queries in another.
     */
    public function testDeletesAreVisibleAcrossConnections(): void
    {
        $itemExistsInCoroutine2 = null;

        run(function () use (&$itemExistsInCoroutine2) {
            // Setup: Insert and then delete
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->table('pool_test_items')->insert([
                'name' => 'Delete test item',
                'value' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Delete it
            $connection1->table('pool_test_items')
                ->where('name', 'Delete test item')
                ->delete();

            $pooled1->release();
            usleep(1000);

            // Coroutine 2: Try to find the deleted item
            go(function () use (&$itemExistsInCoroutine2) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $item = $connection2->table('pool_test_items')
                    ->where('name', 'Delete test item')
                    ->first();

                $itemExistsInCoroutine2 = $item !== null;

                $pooled2->release();
            });
        });

        $this->assertFalse(
            $itemExistsInCoroutine2,
            'Deleted item should not be visible to another pooled connection'
        );
    }

    /**
     * Test that committed transactions are visible to other connections.
     */
    public function testCommittedTransactionsAreVisibleAcrossConnections(): void
    {
        $dataVisibleInCoroutine2 = null;

        run(function () use (&$dataVisibleInCoroutine2) {
            // Coroutine 1: Insert within a transaction
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->beginTransaction();
            $connection1->table('pool_test_items')->insert([
                'name' => 'Transaction item',
                'value' => 777,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $connection1->commit();

            $pooled1->release();
            usleep(1000);

            // Coroutine 2: Read the committed data
            go(function () use (&$dataVisibleInCoroutine2) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $item = $connection2->table('pool_test_items')
                    ->where('name', 'Transaction item')
                    ->first();

                $dataVisibleInCoroutine2 = $item;

                $pooled2->release();
            });
        });

        $this->assertNotNull(
            $dataVisibleInCoroutine2,
            'Committed transaction data should be visible to another pooled connection'
        );
        $this->assertEquals(777, $dataVisibleInCoroutine2->value);
    }

    /**
     * Test that rolled back transactions are NOT visible to other connections.
     */
    public function testRolledBackTransactionsAreNotVisibleAcrossConnections(): void
    {
        $dataVisibleInCoroutine2 = null;

        run(function () use (&$dataVisibleInCoroutine2) {
            // Coroutine 1: Insert within a transaction, then rollback
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->beginTransaction();
            $connection1->table('pool_test_items')->insert([
                'name' => 'Rollback item',
                'value' => 888,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $connection1->rollBack();

            $pooled1->release();
            usleep(1000);

            // Coroutine 2: Try to find the rolled back data
            go(function () use (&$dataVisibleInCoroutine2) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $item = $connection2->table('pool_test_items')
                    ->where('name', 'Rollback item')
                    ->first();

                $dataVisibleInCoroutine2 = $item;

                $pooled2->release();
            });
        });

        $this->assertNull(
            $dataVisibleInCoroutine2,
            'Rolled back transaction data should NOT be visible to another pooled connection'
        );
    }

    /**
     * Test concurrent writes from multiple pooled connections.
     */
    public function testConcurrentWritesFromMultipleConnections(): void
    {
        $totalCount = null;

        run(function () use (&$totalCount) {
            // Launch multiple coroutines that each write data
            $coroutineCount = 3;
            $itemsPerCoroutine = 5;

            for ($c = 1; $c <= $coroutineCount; ++$c) {
                go(function () use ($c, $itemsPerCoroutine) {
                    $pooled = $this->getPooledConnection();
                    $connection = $pooled->getConnection();

                    for ($i = 1; $i <= $itemsPerCoroutine; ++$i) {
                        $connection->table('pool_test_items')->insert([
                            'name' => "Coroutine {$c} Item {$i}",
                            'value' => ($c * 100) + $i,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $pooled->release();
                });
            }
        });

        // Small delay to ensure all coroutines complete
        usleep(10000);

        run(function () use (&$totalCount) {
            // Verify all items were written
            $pooled = $this->getPooledConnection();
            $connection = $pooled->getConnection();

            $totalCount = $connection->table('pool_test_items')->count();

            $pooled->release();
        });

        $this->assertEquals(
            15, // 3 coroutines * 5 items each
            $totalCount,
            'All items from concurrent coroutines should be persisted'
        );
    }
}
