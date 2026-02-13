<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Database\Connection;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use ReflectionProperty;

use function Hypervel\Coroutine\go;

/**
 * Tests that connection state is properly reset when released back to the pool.
 *
 * These tests verify that per-request state on a Connection does not leak
 * to subsequent requests that reuse the same pooled connection.
 *
 * @internal
 * @coversNothing
 */
class PooledConnectionStateTest extends PostgresTestCase
{
    /**
     * Helper to get a PooledConnection directly from the pool.
     */
    protected function getPooledConnection(): PooledConnection
    {
        $factory = $this->app->make(PoolFactory::class);
        $pool = $factory->getPool($this->driver);

        return $pool->get();
    }

    public function testQueryLoggingStateDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2LoggingState = null;
        $coroutine2QueryLog = null;

        $pooled1 = $this->getPooledConnection();
        $connection1 = $pooled1->getConnection();

        $connection1->enableQueryLog();
        $connection1->select('SELECT 1');

        $this->assertTrue($connection1->logging());
        $this->assertNotEmpty($connection1->getQueryLog());

        $pooled1->release();
        usleep(1000);

        go(function () use (&$coroutine2LoggingState, &$coroutine2QueryLog) {
            $pooled2 = $this->getPooledConnection();
            $connection2 = $pooled2->getConnection();

            $coroutine2LoggingState = $connection2->logging();
            $coroutine2QueryLog = $connection2->getQueryLog();

            $pooled2->release();
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

    public function testQueryDurationHandlersDoNotLeakBetweenCoroutines(): void
    {
        $coroutine2HandlerCount = null;

        $pooled1 = $this->getPooledConnection();
        $connection1 = $pooled1->getConnection();

        $connection1->whenQueryingForLongerThan(1000, function () {
            // Handler that would fire after 1 second of queries
        });

        $reflection = new ReflectionProperty(Connection::class, 'queryDurationHandlers');
        $this->assertCount(1, $reflection->getValue($connection1));

        $pooled1->release();
        usleep(1000);

        go(function () use (&$coroutine2HandlerCount) {
            $pooled2 = $this->getPooledConnection();
            $connection2 = $pooled2->getConnection();

            $reflection = new ReflectionProperty(Connection::class, 'queryDurationHandlers');
            $coroutine2HandlerCount = count($reflection->getValue($connection2));

            $pooled2->release();
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

        $pooled1 = $this->getPooledConnection();
        $connection1 = $pooled1->getConnection();

        for ($i = 0; $i < 10; ++$i) {
            $connection1->select('SELECT pg_sleep(0.001)');
        }

        $duration1 = $connection1->totalQueryDuration();
        $this->assertGreaterThan(0, $duration1);

        $pooled1->release();
        usleep(1000);

        go(function () use (&$coroutine2Duration) {
            $pooled2 = $this->getPooledConnection();
            $connection2 = $pooled2->getConnection();

            $coroutine2Duration = $connection2->totalQueryDuration();

            $pooled2->release();
        });

        $this->assertEquals(
            0.0,
            $coroutine2Duration,
            'Total query duration should be reset for new coroutine (state leaked from previous)'
        );
    }

    public function testBeforeStartingTransactionCallbacksDoNotLeakBetweenCoroutines(): void
    {
        $callbackCalledInCoroutine2 = false;

        $pooled1 = $this->getPooledConnection();
        $connection1 = $pooled1->getConnection();

        $connection1->beforeStartingTransaction(function () use (&$callbackCalledInCoroutine2) {
            $callbackCalledInCoroutine2 = true;
        });

        $pooled1->release();
        usleep(1000);

        go(function () use (&$callbackCalledInCoroutine2) {
            $callbackCalledInCoroutine2 = false;

            $pooled2 = $this->getPooledConnection();
            $connection2 = $pooled2->getConnection();

            $connection2->beginTransaction();
            $connection2->rollBack();

            $pooled2->release();
        });

        $this->assertFalse(
            $callbackCalledInCoroutine2,
            'beforeStartingTransaction callback from previous coroutine should not fire (state leaked)'
        );
    }

    public function testReadOnWriteConnectionFlagDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2UsesWriteForReads = null;

        $pooled1 = $this->getPooledConnection();
        $connection1 = $pooled1->getConnection();

        $connection1->useWriteConnectionWhenReading(true);

        $pooled1->release();
        usleep(1000);

        go(function () use (&$coroutine2UsesWriteForReads) {
            $pooled2 = $this->getPooledConnection();
            $connection2 = $pooled2->getConnection();

            $reflection = new ReflectionProperty(Connection::class, 'readOnWriteConnection');
            $coroutine2UsesWriteForReads = $reflection->getValue($connection2);

            $pooled2->release();
        });

        $this->assertFalse(
            $coroutine2UsesWriteForReads,
            'readOnWriteConnection flag should be false for new coroutine (state leaked from previous)'
        );
    }

    public function testPretendingFlagDoesNotLeakBetweenCoroutines(): void
    {
        $coroutine2Pretending = null;

        $pooled1 = $this->getPooledConnection();
        $connection1 = $pooled1->getConnection();

        $reflection = new ReflectionProperty(Connection::class, 'pretending');
        $reflection->setValue($connection1, true);

        $pooled1->release();
        usleep(1000);

        go(function () use (&$coroutine2Pretending) {
            $pooled2 = $this->getPooledConnection();
            $connection2 = $pooled2->getConnection();

            $coroutine2Pretending = $connection2->pretending();

            $pooled2->release();
        });

        $this->assertFalse(
            $coroutine2Pretending,
            'Pretending flag should be false for new coroutine (state leaked from previous)'
        );
    }
}
