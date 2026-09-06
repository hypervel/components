<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class DatabaseTransactionsTest extends TestCase
{
    public function testBeginTransactionRestoresTheDispatcherWhenItFails(): void
    {
        $failure = new RuntimeException('Transaction begin failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('setTransactionManager')->once();
        $connection->shouldReceive('getEventDispatcher')->once()->andReturn($dispatcher);
        $connection->shouldReceive('unsetEventDispatcher')->once()->ordered();
        $connection->shouldReceive('beginTransaction')->once()->andThrow($failure)->ordered();
        $connection->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->ordered();
        $testCase = $this->createTestCase($connection);

        try {
            $testCase->beginDatabaseTransactionWork();
            $this->fail('Expected the transaction begin failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testRollbackRestoresTheDispatcherWhenItFails(): void
    {
        $failure = new RuntimeException('Transaction rollback failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getEventDispatcher')->once()->andReturn($dispatcher);
        $connection->shouldReceive('unsetEventDispatcher')->once()->ordered();
        $connection->shouldReceive('forgetRecordModificationState')->once()->ordered();
        $connection->shouldReceive('rollBack')->once()->andThrow($failure)->ordered();
        $connection->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->ordered();
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
    private function createTestCase(ConnectionInterface $connection): DatabaseTransactionsTestCase
    {
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);
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
