<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tmp;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Channel;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Support\DatabaseIntegrationTestCase;

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
class DatabaseCoroutineSafetyTest extends DatabaseIntegrationTestCase
{
    use RefreshDatabase;

    protected function getDatabaseDriver(): string
    {
        return 'pgsql';
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset static state
        UnguardedTestUser::$eventLog = [];
        Model::reguard(); // Ensure guarded by default
    }

    // =========================================================================
    // Model::unguarded() Coroutine Safety Tests
    // =========================================================================

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
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // State should be restored even after exception
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

            // Should still be unguarded after inner callback
            $this->assertTrue(Model::isUnguarded());
        });

        $this->assertFalse(Model::isUnguarded());
    }

    /**
     * This test verifies coroutine isolation for Model::unguarded().
     *
     * EXPECTED: Coroutine 1 being unguarded should NOT affect Coroutine 2.
     * CURRENT BUG: Uses static property, so state leaks between coroutines.
     */
    public function testUnguardedIsCoroutineIsolated(): void
    {
        $results = [];

        run(function () use (&$results) {
            $channel = new Channel(2);
            $waiter = new WaitGroup();

            // Coroutine 1: Runs unguarded
            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                Model::unguarded(function () use ($channel) {
                    $channel->push(['coroutine' => 1, 'unguarded' => Model::isUnguarded()]);
                    usleep(50000); // 50ms
                });
                $waiter->done();
            });

            // Coroutine 2: Should NOT be unguarded
            $waiter->add(1);
            go(function () use ($channel, $waiter) {
                usleep(10000); // 10ms - ensure coroutine 1 is inside unguarded()
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

    // =========================================================================
    // DatabaseManager::usingConnection() Coroutine Safety Tests
    // =========================================================================

    public function testUsingConnectionChangesDefaultWithinCallback(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        // Use a different connection name for the test
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
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($originalDefault, $manager->getDefaultConnection());
    }

    /**
     * This test verifies coroutine isolation for DatabaseManager::usingConnection().
     *
     * EXPECTED: Coroutine 1's connection override should NOT affect Coroutine 2.
     * CURRENT BUG: Mutates global config, so state leaks between coroutines.
     */
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

            // Coroutine 1: Changes default connection
            $waiter->add(1);
            go(function () use ($channel, $waiter, $manager, $testConnection) {
                $manager->usingConnection($testConnection, function () use ($channel, $manager) {
                    $channel->push(['coroutine' => 1, 'connection' => $manager->getDefaultConnection()]);
                    usleep(50000); // 50ms
                });
                $waiter->done();
            });

            // Coroutine 2: Should still see original default
            $waiter->add(1);
            go(function () use ($channel, $waiter, $manager) {
                usleep(10000); // 10ms - ensure coroutine 1 is inside usingConnection()
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

    /**
     * Test that DB::connection() without explicit name respects usingConnection().
     */
    public function testUsingConnectionAffectsDbConnection(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        // Verify default connection before
        $connectionBefore = DB::connection();
        $this->assertSame($originalDefault, $connectionBefore->getName());

        // Use a different connection
        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $manager->usingConnection($testConnection, function () use ($testConnection) {
            // DB::connection() without args should use the overridden connection
            $connection = DB::connection();
            $this->assertSame(
                $testConnection,
                $connection->getName(),
                'DB::connection() should return the usingConnection override'
            );
        });

        // Verify restored after
        $connectionAfter = DB::connection();
        $this->assertSame($originalDefault, $connectionAfter->getName());
    }

    /**
     * Test that Schema::connection() without explicit name respects usingConnection().
     */
    public function testUsingConnectionAffectsSchemaConnection(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->get(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        // Use a different connection
        $testConnection = $originalDefault === 'pgsql' ? 'default' : 'pgsql';

        $manager->usingConnection($testConnection, function () use ($testConnection) {
            // Schema::connection() without args should use the overridden connection
            $schemaBuilder = Schema::connection();
            $connectionName = $schemaBuilder->getConnection()->getName();

            $this->assertSame(
                $testConnection,
                $connectionName,
                'Schema::connection() should return schema builder for usingConnection override'
            );
        });
    }

    // =========================================================================
    // Connection::beforeExecuting() Callback Isolation Tests
    // =========================================================================

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

    /**
     * Test that clearBeforeExecutingCallbacks() method exists and works.
     *
     * This method is needed for pool release cleanup.
     */
    public function testClearBeforeExecutingCallbacksExists(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        $called = false;
        $connection->beforeExecuting(function () use (&$called) {
            $called = true;
        });

        // Method should exist
        $this->assertTrue(method_exists($connection, 'clearBeforeExecutingCallbacks'));

        // Clear callbacks
        $connection->clearBeforeExecutingCallbacks();

        // Callback should not be called after clearing
        $connection->select('SELECT 1');
        $this->assertFalse($called);
    }

    // =========================================================================
    // Connection Error Counting Tests
    // =========================================================================

    /**
     * Test that Connection tracks error count.
     */
    public function testConnectionTracksErrorCount(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        // Method should exist
        $this->assertTrue(method_exists($connection, 'getErrorCount'));

        $initialCount = $connection->getErrorCount();

        // Trigger an error
        try {
            $connection->select('SELECT * FROM nonexistent_table_xyz');
        } catch (\Throwable) {
            // Expected
        }

        $this->assertGreaterThan($initialCount, $connection->getErrorCount());
    }

    // =========================================================================
    // PooledConnection Configuration Tests
    // =========================================================================

    /**
     * Test that pooled connections have event dispatcher configured.
     */
    public function testPooledConnectionHasEventDispatcher(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->getDatabaseDriver());

        $dispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($dispatcher, 'Pooled connection should have event dispatcher configured');
    }

    /**
     * Test that pooled connections have transaction manager configured.
     */
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
