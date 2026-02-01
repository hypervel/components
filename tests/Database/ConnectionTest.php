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
}
