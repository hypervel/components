<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PDO;
use RuntimeException;

class DatabaseTransactionsTest extends TestCase
{
    use VerifiesDoubles;

    public function testBeginTransactionRestoresTheDispatcherWhenItFails(): void
    {
        $failure = new RuntimeException('Transaction begin failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('setTransactionManager');
        $connection->expects('getEventDispatcher')->returns($dispatcher);
        $connection->expects('unsetEventDispatcher')->ordered();
        $connection->expects('beginTransaction')->throws($failure)->ordered();
        $connection->expects('setEventDispatcher')->with(Argument::same($dispatcher))->ordered();
        $testCase = $this->createTestCase($connection);

        try {
            $testCase->beginDatabaseTransactionWork();
            $this->fail('Expected the transaction begin failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testTestTransactionIsRolledBackWhenTheTestLeavesATransactionOpen(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new SQLiteConnection($pdo, ':memory:');
        $testCase = $this->createTestCase($connection);

        // The wrapper transaction, followed by one the test leaves open.
        $connection->beginTransaction();
        $connection->beginTransaction();

        $testCase->rollbackDatabaseTransactionWork();

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
    }

    public function testRollbackRestoresTheDispatcherWhenItFails(): void
    {
        $failure = new RuntimeException('Transaction rollback failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getEventDispatcher')->returns($dispatcher);
        $connection->expects('unsetEventDispatcher')->ordered();
        $connection->expects('forgetRecordModificationState')->ordered();
        $connection->expects('rollBack')->throws($failure)->ordered();
        $connection->expects('setEventDispatcher')->with(Argument::same($dispatcher))->ordered();
        $testCase = $this->createTestCase($connection);

        try {
            $testCase->rollbackDatabaseTransactionWork();
            $this->fail('Expected the transaction rollback failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * Create a test case for the given connection.
     */
    private function createTestCase(Connection $connection): DatabaseTransactionsTestCase
    {
        $database = Double::for(DatabaseManager::class)->strict();
        $database->expects('connection')->with(null)->returns($connection);
        $app = new Container;
        $app->instance('db', $database);

        return new DatabaseTransactionsTestCase($app);
    }
}

class DatabaseTransactionsTestCase
{
    use DatabaseTransactions {
        beginDatabaseTransactionWork as public;
        rollbackDatabaseTransactionWork as public;
    }

    /**
     * Create a test case without automatic database lifecycle hooks.
     */
    public function __construct(public Container $app)
    {
    }
}
