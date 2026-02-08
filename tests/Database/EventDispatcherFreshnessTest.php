<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;

/**
 * Tests that database connections use the current event dispatcher.
 *
 * In the testing environment, connections are cached statically to prevent
 * pool exhaustion. When Event::fake() swaps the dispatcher, cached connections
 * must dispatch events through the new (fake) dispatcher, not a stale reference.
 *
 * @internal
 * @coversNothing
 */
class EventDispatcherFreshnessTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Test that ConnectionEstablished events go through the current dispatcher.
     *
     * PooledConnection::refresh() dispatches this event via the container.
     * When the container changes between tests, cached connections are flushed,
     * ensuring new PooledConnections are created with the current container.
     */
    public function testConnectionEstablishedUsesCurrentDispatcher(): void
    {
        // Ensure a connection exists and is cached
        $connection = DB::connection();
        $connection->select('SELECT 1');

        // Disconnect to force reconnection on next query
        $connection->disconnect();

        // Swap to fake dispatcher AFTER connection is cached
        Event::fake([ConnectionEstablished::class]);

        // Trigger reconnection - this should dispatch through the fake
        $connection->select('SELECT 1');

        Event::assertDispatched(ConnectionEstablished::class);
    }

    /**
     * Test that TransactionBeginning events go through the current dispatcher.
     *
     * Connection::fireConnectionEvent() dispatches via $this->events. When
     * Event::fake() swaps the dispatcher, a rebinding callback updates the
     * cached connection's dispatcher to use the fake.
     */
    public function testTransactionBeginningUsesCurrentDispatcher(): void
    {
        // Ensure a connection exists and is cached
        $connection = DB::connection();
        $connection->select('SELECT 1');

        // Swap to fake dispatcher AFTER connection is cached
        Event::fake([TransactionBeginning::class]);

        // Start a transaction - this should dispatch through the fake
        $connection->beginTransaction();
        $connection->rollBack();

        Event::assertDispatched(TransactionBeginning::class);
    }
}
