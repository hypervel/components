<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class EventConnectionEstablishedTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Test that ConnectionEstablished fires when a connection is re-established.
     *
     * Note: Laravel's version of this test uses migrate:fresh to trigger reconnection
     * (because Laravel's db:wipe disconnects after dropping tables). In Hypervel with
     * Swoole connection pooling, db:wipe does NOT disconnect - the pooled connection
     * remains valid after dropping tables. So we explicitly disconnect and query to
     * trigger the reconnection path.
     */
    public function testConnectionEstablishedEventFiringOnReconnect(): void
    {
        // Get a connection and disconnect it to simulate a dropped connection
        $connection = DB::connection();
        $connection->disconnect();

        // Fake the event after disconnect (before reconnection happens)
        Event::fake([ConnectionEstablished::class]);
        Event::assertNotDispatched(ConnectionEstablished::class);

        // Run a query - this triggers the reconnector which re-establishes the connection
        $connection->select('SELECT 1');

        // Assert the event was dispatched during reconnection
        Event::assertDispatched(ConnectionEstablished::class);
    }
}
