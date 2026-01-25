<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

/**
 * Tests that file-based SQLite works correctly with connection pooling.
 *
 * Unlike :memory: databases, file-based SQLite doesn't need special handling -
 * all pooled connections point to the same file on disk, and SQLite's built-in
 * file locking handles concurrency.
 *
 * @internal
 * @coversNothing
 * @group integration
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
        // Clean up the test database file
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

        $config->set('databases.sqlite_file', $connectionConfig);
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

    public function testCanWriteAndReadFromFileBasedSqlite(): void
    {
        $connection = DB::connection('sqlite_file');

        $connection->table('pool_test_items')->insert([
            'name' => 'Test Item',
            'value' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = $connection->table('pool_test_items')->where('name', 'Test Item')->first();

        $this->assertNotNull($item);
        $this->assertSame('Test Item', $item->name);
        $this->assertEquals(100, $item->value);
    }

    public function testMultipleConnectionsShareSameData(): void
    {
        // Write using one connection call
        DB::connection('sqlite_file')->table('pool_test_items')->insert([
            'name' => 'Shared Item',
            'value' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Read using another connection call (may get different pooled connection)
        $item = DB::connection('sqlite_file')->table('pool_test_items')
            ->where('name', 'Shared Item')
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(42, $item->value);

        // Update and verify
        DB::connection('sqlite_file')->table('pool_test_items')
            ->where('name', 'Shared Item')
            ->update(['value' => 99]);

        $updated = DB::connection('sqlite_file')->table('pool_test_items')
            ->where('name', 'Shared Item')
            ->first();

        $this->assertEquals(99, $updated->value);
    }

    public function testTransactionsWorkWithFileBasedSqlite(): void
    {
        DB::connection('sqlite_file')->transaction(function ($connection) {
            $connection->table('pool_test_items')->insert([
                'name' => 'Transaction Item',
                'value' => 500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $item = DB::connection('sqlite_file')->table('pool_test_items')
            ->where('name', 'Transaction Item')
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(500, $item->value);
    }

    public function testRollbackWorksWithFileBasedSqlite(): void
    {
        try {
            DB::connection('sqlite_file')->transaction(function ($connection) {
                $connection->table('pool_test_items')->insert([
                    'name' => 'Rollback Item',
                    'value' => 999,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                throw new \RuntimeException('Force rollback');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $item = DB::connection('sqlite_file')->table('pool_test_items')
            ->where('name', 'Rollback Item')
            ->first();

        $this->assertNull($item, 'Item should not exist after rollback');
    }

    public function testMultipleInsertsAndQueries(): void
    {
        $connection = DB::connection('sqlite_file');

        // Insert multiple items
        for ($i = 1; $i <= 10; $i++) {
            $connection->table('pool_test_items')->insert([
                'name' => "Item {$i}",
                'value' => $i * 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Verify count
        $this->assertSame(10, $connection->table('pool_test_items')->count());

        // Verify sum
        $this->assertEquals(550, $connection->table('pool_test_items')->sum('value'));

        // Verify ordering
        $items = $connection->table('pool_test_items')->orderBy('value', 'desc')->get();
        $this->assertSame('Item 10', $items->first()->name);
        $this->assertSame('Item 1', $items->last()->name);
    }
}
