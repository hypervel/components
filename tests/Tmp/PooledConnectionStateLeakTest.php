<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tmp;

use Hypervel\Database\Connection;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Support\Facades\DB;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

/**
 * Tests that connection state is properly reset when released back to the pool.
 *
 * These tests verify that per-request state on a Connection does not leak
 * to subsequent requests that reuse the same pooled connection.
 *
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class PooledConnectionStateLeakTest extends TmpIntegrationTestCase
{
    /**
     * Helper to get a PooledConnection directly from the pool.
     */
    protected function getPooledConnection(): PooledConnection
    {
        $factory = $this->app->get(PoolFactory::class);
        $pool = $factory->getPool($this->getDatabaseDriver());

        return $pool->get();
    }

    // =========================================================================
    // Query Logging State Leak Tests
    // =========================================================================

    public function testQueryLoggingStateDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2LoggingState = null;
        $coroutine2QueryLog = null;

        run(function () use (&$coroutine2LoggingState, &$coroutine2QueryLog) {
            // Coroutine 1: Enable logging, run query, release connection
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->enableQueryLog();
            $connection1->select('SELECT 1');

            // Verify state is set
            $this->assertTrue($connection1->logging());
            $this->assertNotEmpty($connection1->getQueryLog());

            // Release back to pool
            $pooled1->release();

            // Small delay to ensure release completes
            usleep(1000);

            // Coroutine 2: Get connection (likely same one), check state
            go(function () use (&$coroutine2LoggingState, &$coroutine2QueryLog) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $coroutine2LoggingState = $connection2->logging();
                $coroutine2QueryLog = $connection2->getQueryLog();

                $pooled2->release();
            });
        });

        $this->assertFalse(
            $coroutine2LoggingState,
            'Query logging should be disabled for new coroutine (state leaked from previous)'
        );
        $this->assertEmpty(
            $coroutine2QueryLog,
            'Query log should be empty for new coroutine (state leaked from previous)'
        );
    }

    // =========================================================================
    // Query Duration Handler Leak Tests
    // =========================================================================

    public function testQueryDurationHandlersDoNotLeakBetweenCoroutines(): void
    {
        $coroutine2HandlerCount = null;

        run(function () use (&$coroutine2HandlerCount) {
            // Coroutine 1: Register a duration handler, release connection
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->whenQueryingForLongerThan(1000, function () {
                // Handler that would fire after 1 second of queries
            });

            // Verify handler is registered
            $reflection = new \ReflectionProperty(Connection::class, 'queryDurationHandlers');
            $this->assertCount(1, $reflection->getValue($connection1));

            // Release back to pool
            $pooled1->release();

            usleep(1000);

            // Coroutine 2: Get connection, check if handlers array leaked
            go(function () use (&$coroutine2HandlerCount) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $reflection = new \ReflectionProperty(Connection::class, 'queryDurationHandlers');
                $coroutine2HandlerCount = count($reflection->getValue($connection2));

                $pooled2->release();
            });
        });

        $this->assertEquals(
            0,
            $coroutine2HandlerCount,
            'Query duration handlers array should be empty for new coroutine (state leaked from previous)'
        );
    }

    public function testTotalQueryDurationDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2Duration = null;

        run(function () use (&$coroutine2Duration) {
            // Coroutine 1: Run queries to accumulate duration
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            // Run multiple queries to accumulate significant duration
            for ($i = 0; $i < 10; $i++) {
                $connection1->select('SELECT pg_sleep(0.001)'); // 1ms each
            }

            $duration1 = $connection1->totalQueryDuration();
            $this->assertGreaterThan(0, $duration1);

            $pooled1->release();

            usleep(1000);

            // Coroutine 2: Check duration starts fresh
            go(function () use (&$coroutine2Duration) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $coroutine2Duration = $connection2->totalQueryDuration();

                $pooled2->release();
            });
        });

        $this->assertEquals(
            0.0,
            $coroutine2Duration,
            'Total query duration should be reset for new coroutine (state leaked from previous)'
        );
    }

    // =========================================================================
    // beforeStartingTransaction Callback Leak Tests
    // =========================================================================

    public function testBeforeStartingTransactionCallbacksDoNotLeakBetweenCoroutines(): void
    {
        $callbackCalledInCoroutine2 = false;

        run(function () use (&$callbackCalledInCoroutine2) {
            // Coroutine 1: Register a transaction callback, release connection
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->beforeStartingTransaction(function () use (&$callbackCalledInCoroutine2) {
                $callbackCalledInCoroutine2 = true;
            });

            $pooled1->release();

            usleep(1000);

            // Coroutine 2: Get connection, start transaction, check if callback fires
            go(function () use (&$callbackCalledInCoroutine2) {
                $callbackCalledInCoroutine2 = false; // Reset before test

                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                // Start a transaction - callback from coroutine 1 should NOT fire
                $connection2->beginTransaction();
                $connection2->rollBack();

                $pooled2->release();
            });
        });

        $this->assertFalse(
            $callbackCalledInCoroutine2,
            'beforeStartingTransaction callback from previous coroutine should not fire (state leaked)'
        );
    }

    // =========================================================================
    // readOnWriteConnection Flag Leak Tests
    // =========================================================================

    public function testReadOnWriteConnectionFlagDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2UsesWriteForReads = null;

        run(function () use (&$coroutine2UsesWriteForReads) {
            // Coroutine 1: Enable write connection for reads, release
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            $connection1->useWriteConnectionWhenReading(true);

            $pooled1->release();

            usleep(1000);

            // Coroutine 2: Check if flag is still set
            go(function () use (&$coroutine2UsesWriteForReads) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                // Use reflection to check the protected property
                $reflection = new \ReflectionProperty(Connection::class, 'readOnWriteConnection');
                $coroutine2UsesWriteForReads = $reflection->getValue($connection2);

                $pooled2->release();
            });
        });

        $this->assertFalse(
            $coroutine2UsesWriteForReads,
            'readOnWriteConnection flag should be false for new coroutine (state leaked from previous)'
        );
    }

    // =========================================================================
    // Pretending Flag Leak Tests
    // =========================================================================

    public function testPretendingFlagDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2Pretending = null;

        run(function () use (&$coroutine2Pretending) {
            // Coroutine 1: Set pretending via reflection (simulating interrupted pretend())
            $pooled1 = $this->getPooledConnection();
            $connection1 = $pooled1->getConnection();

            // Simulate a scenario where pretending wasn't properly reset
            // (e.g., coroutine killed mid-pretend)
            $reflection = new \ReflectionProperty(Connection::class, 'pretending');
            $reflection->setValue($connection1, true);

            $pooled1->release();

            usleep(1000);

            // Coroutine 2: Check if pretending is still set
            go(function () use (&$coroutine2Pretending) {
                $pooled2 = $this->getPooledConnection();
                $connection2 = $pooled2->getConnection();

                $coroutine2Pretending = $connection2->pretending();

                $pooled2->release();
            });
        });

        $this->assertFalse(
            $coroutine2Pretending,
            'Pretending flag should be false for new coroutine (state leaked from previous)'
        );
    }
}
