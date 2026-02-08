<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;

/**
 * Tests for database Connection behavior.
 *
 * @internal
 * @coversNothing
 */
class ConnectionTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function defineEnvironment(Application $app): void
    {
        // Suppress "Database connection refreshing" warnings during disconnect tests
        $app->get('config')->set(StdoutLoggerInterface::class . '.log_level', []);
    }

    /**
     * Test that disconnect() rolls back any open transaction.
     *
     * In Swoole's connection pooling environment, connections are reused across
     * requests/coroutines. If a connection is disconnected (or purged) while a
     * transaction is open, the transaction must be rolled back to prevent the
     * dirty state from leaking to the next user of the pooled connection.
     */
    public function testDisconnectRollsBackOpenTransaction(): void
    {
        $connection = DB::connection();

        // Start a transaction
        $connection->beginTransaction();
        $this->assertSame(1, $connection->transactionLevel());

        // Disconnect should roll back the transaction
        $connection->disconnect();

        // After disconnect, transaction level should be reset
        // (the Connection wrapper's state should be clean)
        $this->assertSame(0, $connection->transactionLevel());
    }

    /**
     * Test that disconnect() rolls back nested transactions (savepoints).
     */
    public function testDisconnectRollsBackNestedTransactions(): void
    {
        $connection = DB::connection();

        // Start nested transactions
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->assertSame(3, $connection->transactionLevel());

        // Disconnect should roll back all transaction levels
        $connection->disconnect();

        $this->assertSame(0, $connection->transactionLevel());
    }

    /**
     * Test that disconnect() works correctly when no transaction is open.
     */
    public function testDisconnectWithNoTransactionDoesNotError(): void
    {
        $connection = DB::connection();

        $this->assertSame(0, $connection->transactionLevel());

        // Should not throw any error
        $connection->disconnect();

        $this->assertSame(0, $connection->transactionLevel());
    }

    /**
     * Test that purge() cleans up transactions via disconnect().
     */
    public function testPurgeRollsBackOpenTransaction(): void
    {
        $connection = DB::connection();
        $connectionName = $connection->getName();

        // Start a transaction
        $connection->beginTransaction();
        $this->assertSame(1, $connection->transactionLevel());

        // Purge should clean up the transaction
        DB::purge($connectionName);

        // Get a fresh connection - it should have no transaction
        $freshConnection = DB::connection($connectionName);
        $this->assertSame(0, $freshConnection->transactionLevel());
    }

    /**
     * Test that multiple whenQueryingForLongerThan handlers work correctly.
     *
     * Uses a single persistent listener internally, but all handlers should fire.
     */
    public function testMultipleQueryDurationHandlersAllFire(): void
    {
        $connection = DB::connection();
        $connection->resetTotalQueryDuration();

        $fired = ['handler1' => false, 'handler2' => false, 'handler3' => false];

        // Register multiple handlers with very low thresholds
        $connection->whenQueryingForLongerThan(0, function () use (&$fired) {
            $fired['handler1'] = true;
        });
        $connection->whenQueryingForLongerThan(0, function () use (&$fired) {
            $fired['handler2'] = true;
        });
        $connection->whenQueryingForLongerThan(0, function () use (&$fired) {
            $fired['handler3'] = true;
        });

        // Execute a query to trigger the handlers
        $connection->select('SELECT 1');

        $this->assertTrue($fired['handler1'], 'Handler 1 should have fired');
        $this->assertTrue($fired['handler2'], 'Handler 2 should have fired');
        $this->assertTrue($fired['handler3'], 'Handler 3 should have fired');
    }

    /**
     * Test that handlers respect their individual thresholds.
     */
    public function testQueryDurationHandlersRespectThresholds(): void
    {
        $connection = DB::connection();
        $connection->resetTotalQueryDuration();

        $fired = ['low' => false, 'high' => false];

        // Low threshold - should fire
        $connection->whenQueryingForLongerThan(0, function () use (&$fired) {
            $fired['low'] = true;
        });

        // Very high threshold - should not fire
        $connection->whenQueryingForLongerThan(999999999, function () use (&$fired) {
            $fired['high'] = true;
        });

        $connection->select('SELECT 1');

        $this->assertTrue($fired['low'], 'Low threshold handler should have fired');
        $this->assertFalse($fired['high'], 'High threshold handler should not have fired');
    }

    /**
     * Test that resetForPool clears handlers but new handlers still work.
     */
    public function testHandlersWorkAfterResetForPool(): void
    {
        $connection = DB::connection();
        $connection->resetTotalQueryDuration();

        $oldHandlerFired = false;
        $newHandlerFired = false;

        // Register a handler
        $connection->whenQueryingForLongerThan(0, function () use (&$oldHandlerFired) {
            $oldHandlerFired = true;
        });

        // Reset the connection (simulating return to pool)
        $connection->resetForPool();
        $connection->resetTotalQueryDuration();

        // Register a new handler after reset
        $connection->whenQueryingForLongerThan(0, function () use (&$newHandlerFired) {
            $newHandlerFired = true;
        });

        // Execute a query
        $connection->select('SELECT 1');

        $this->assertFalse($oldHandlerFired, 'Old handler should not fire after resetForPool');
        $this->assertTrue($newHandlerFired, 'New handler should fire after resetForPool');
    }

    /**
     * Test that handlers only fire once until allowQueryDurationHandlersToRunAgain is called.
     */
    public function testHandlersOnlyFireOnceUntilReset(): void
    {
        $connection = DB::connection();
        $connection->resetTotalQueryDuration();

        $fireCount = 0;

        $connection->whenQueryingForLongerThan(0, function () use (&$fireCount) {
            ++$fireCount;
        });

        // First query - should fire
        $connection->select('SELECT 1');
        $this->assertSame(1, $fireCount);

        // Second query - should NOT fire again (already ran)
        $connection->select('SELECT 1');
        $this->assertSame(1, $fireCount);

        // Allow handlers to run again
        $connection->allowQueryDurationHandlersToRunAgain();

        // Third query - should fire again
        $connection->select('SELECT 1');
        $this->assertSame(2, $fireCount);
    }
}
