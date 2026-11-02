<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database;

use Hyperf\Context\Context;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Events\TransactionRolledBack;
use Hypervel\Database\TransactionManager;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class TransactionManagerTest extends TestCase
{
    protected TransactionManager $manager;

    protected array $calls = [];

    /**
     * @var array<int, int>
     */
    protected array $levels = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new TransactionManager();
    }

    protected function tearDown(): void
    {
        Context::destroy('_db.transactions');

        parent::tearDown();
    }

    public function testCommitCallbackRunsImmediatelyWithoutTransaction()
    {
        $this->manager->addCallback($this->record('committed'));
        $this->manager->addCallback($this->record('rolled back'), TransactionRolledBack::class);

        $this->assertSame(['committed'], $this->calls);
    }

    public function testCallbacksWaitForOutermostCommit()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('outer'));
        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('inner'));

        $this->commitTransaction($connection);
        $this->assertSame([], $this->calls);

        $this->commitTransaction($connection);
        $this->assertSame(['outer', 'inner'], $this->calls);
    }

    public function testCallbacksRunOnCommitWhenEnclosingTransactionIsNotRecorded()
    {
        // RefreshDatabase begins its wrapping transaction without firing an event.
        $connection = $this->connection(level: 1);

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('committed'));

        $this->commitTransaction($connection);
        $this->assertSame(['committed'], $this->calls);
    }

    public function testRollingBackSavepointDiscardsOnlyItsCallbacks()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('outer'));
        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('inner'));
        $this->manager->addCallback($this->record('inner rolled back'), TransactionRolledBack::class);

        $this->rollBackTransaction($connection);
        $this->assertSame(['inner rolled back'], $this->calls);

        $this->commitTransaction($connection);
        $this->assertSame(['inner rolled back', 'outer'], $this->calls);
    }

    public function testReleasedSavepointCallbacksRollBackWithEnclosingTransaction()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('inner'));
        $this->manager->addCallback($this->record('inner rolled back'), TransactionRolledBack::class);
        $this->commitTransaction($connection);

        $this->rollBackTransaction($connection);
        $this->assertSame(['inner rolled back'], $this->calls);
    }

    public function testRolledBackCallbacksDoNotRunOnLaterCommit()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('rolled back transaction'));
        $this->rollBackTransaction($connection);

        $this->beginTransaction($connection);
        $this->commitTransaction($connection);

        $this->assertSame([], $this->calls);
    }

    public function testConnectionsAreTrackedIndependently()
    {
        $default = $this->connection();
        $other = $this->connection();

        $this->beginTransaction($default);
        $this->manager->addCallback($this->record('default'));
        $this->beginTransaction($other);
        $this->manager->addCallback($this->record('other'));

        $this->commitTransaction($other);
        $this->assertSame(['other'], $this->calls);

        $this->commitTransaction($default);
        $this->assertSame(['other', 'default'], $this->calls);
    }

    public function testTransactionDroppedWithoutEventIsRolledBackBeforeAddingCallback()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('dropped'));
        $this->manager->addCallback($this->record('dropped rolled back'), TransactionRolledBack::class);

        // A rollback that loses the connection resets the level without an event.
        $this->levels[spl_object_id($connection)] = 0;

        $this->manager->addCallback($this->record('after'));
        $this->assertSame(['dropped rolled back', 'after'], $this->calls);
    }

    public function testTransactionDroppedWithoutEventIsRolledBackWhenNextTransactionBegins()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('dropped'));
        $this->levels[spl_object_id($connection)] = 0;

        $this->beginTransaction($connection);
        $this->commitTransaction($connection);

        $this->assertSame([], $this->calls);
    }

    public function testCallbackRegisteredByCommitCallbackRunsImmediately()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback(function () {
            $this->manager->addCallback($this->record('nested'));
        });

        $this->commitTransaction($connection);

        $this->assertSame(['nested'], $this->calls);
    }

    public function testDeprecatedRunCallbacksRunsRegisteredCallbacksOnce()
    {
        $connection = $this->connection();

        $this->beginTransaction($connection);
        $this->manager->addCallback($this->record('committed'));

        $this->manager->runCallbacks();
        $this->commitTransaction($connection);

        $this->assertSame(['committed'], $this->calls);
    }

    protected function connection(int $level = 0): ConnectionInterface
    {
        $connection = m::mock(ConnectionInterface::class);
        $this->levels[spl_object_id($connection)] = $level;

        $connection->shouldReceive('transactionLevel')
            ->andReturnUsing(fn () => $this->levels[spl_object_id($connection)]);

        return $connection;
    }

    /**
     * Change the level the way Hyperf does before firing each transaction event.
     */
    protected function beginTransaction(ConnectionInterface $connection): void
    {
        ++$this->levels[spl_object_id($connection)];
        $this->manager->begin($connection);
    }

    protected function commitTransaction(ConnectionInterface $connection): void
    {
        --$this->levels[spl_object_id($connection)];
        $this->manager->commit($connection);
    }

    protected function rollBackTransaction(ConnectionInterface $connection): void
    {
        --$this->levels[spl_object_id($connection)];
        $this->manager->rollback($connection);
    }

    protected function record(string $call): callable
    {
        return function () use ($call) {
            $this->calls[] = $call;
        };
    }
}
