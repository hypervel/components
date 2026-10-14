<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Coroutine\Waiter;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Foundation\Testing\DatabaseTransactionsManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

class DatabaseTransactionsManagerTest extends TestCase
{
    public function testItExecutesCallbacksImmediatelyIfThereIsOnlyOneTransaction(): void
    {
        $testObject = new TestingDatabaseTransactionsManagerTestObject;
        $manager = new DatabaseTransactionsManager([null]);

        $this->beginWrappingTransactions($manager);

        $manager->addCallback(fn () => $testObject->handle());

        $this->assertTrue($testObject->ran);
        $this->assertEquals(1, $testObject->runs);
    }

    public function testItIgnoresTheBaseTransactionForCallbackApplicableTransactions(): void
    {
        $manager = new DatabaseTransactionsManager([null]);

        $this->beginWrappingTransactions($manager);
        $manager->begin('foo', 2);

        $this->assertCount(1, $manager->callbackApplicableTransactions());
        $this->assertEquals(2, $manager->callbackApplicableTransactions()[0]->level);
    }

    public function testCommittingDoesNotRemoveTheBasePendingTransaction(): void
    {
        $manager = new DatabaseTransactionsManager([null]);

        $this->beginWrappingTransactions($manager);

        $manager->begin('foo', 2);
        $manager->commit('foo', 2, 1);

        $this->assertCount(0, $manager->callbackApplicableTransactions());

        $manager->begin('foo', 2);

        $this->assertCount(1, $manager->callbackApplicableTransactions());
        $this->assertEquals(2, $manager->callbackApplicableTransactions()[0]->level);
    }

    public function testItExecutesCallbacksForTheSecondTransaction(): void
    {
        $testObject = new TestingDatabaseTransactionsManagerTestObject;
        $manager = new DatabaseTransactionsManager([null]);
        $this->beginWrappingTransactions($manager);
        $manager->begin('foo', 2);

        $manager->addCallback(fn () => $testObject->handle());

        $this->assertFalse($testObject->ran);

        $manager->commit('foo', 2, 1);
        $manager->commit('foo', 1, 0);
        $this->assertTrue($testObject->ran);
        $this->assertEquals(1, $testObject->runs);
    }

    public function testItAssociatesCallbacksWithNamedConnectionsInsideMultipleWrapperTransactions(): void
    {
        $callbacks = [];
        $manager = new DatabaseTransactionsManager(['default', 'admin']);

        $this->beginWrappingTransactions($manager, ['default', 'admin']);
        $manager->begin('default', 2);
        $manager->begin('admin', 2);

        $manager->addCallback(function () use (&$callbacks): void {
            $callbacks[] = 'default';
        }, 'default');

        $this->assertCount(0, $manager->getPendingTransactions()[0]->getCallbacks());
        $this->assertCount(0, $manager->getPendingTransactions()[1]->getCallbacks());
        $this->assertCount(1, $manager->getPendingTransactions()[2]->getCallbacks());
        $this->assertCount(0, $manager->getPendingTransactions()[3]->getCallbacks());

        $manager->commit('admin', 2, 1);
        $this->assertSame([], $callbacks);

        $manager->commit('default', 2, 1);
        $this->assertSame(['default'], $callbacks);
    }

    public function testItExecutesTransactionCallbacksAtLevelOne(): void
    {
        $manager = new DatabaseTransactionsManager([null]);

        $this->assertFalse($manager->afterCommitCallbacksShouldBeExecuted(0));
        $this->assertTrue($manager->afterCommitCallbacksShouldBeExecuted(1));
        $this->assertFalse($manager->afterCommitCallbacksShouldBeExecuted(2));
    }

    public function testSkipsTheNumberOfConnectionsTransacting(): void
    {
        $manager = new DatabaseTransactionsManager([null]);

        $this->beginWrappingTransactions($manager);
        $manager->begin('foo', 2);

        $this->assertCount(1, $manager->callbackApplicableTransactions());
    }

    public function testPooledChildTransactionsDoNotCountAsTheirParentsWrapper(): void
    {
        $manager = new DatabaseTransactionsManager([null]);
        $this->beginWrappingTransactions($manager);

        (new Waiter)->wait(function () use ($manager): void {
            $called = false;
            $manager->begin('foo', 1);
            $manager->addCallback(function () use (&$called): void {
                $called = true;
            }, 'foo');

            $this->assertFalse($called);
            $this->assertTrue($manager->hasApplicableTransaction('foo'));
            $manager->commit('foo', 1, 0);
            $this->assertTrue($called);
        });

        $this->assertCount(1, $manager->getPendingTransactions());
        $this->assertFalse($manager->hasApplicableTransaction('foo'));
    }

    /**
     * Begin the real wrappers while keeping these manager tests independent of an application.
     */
    protected function beginWrappingTransactions(DatabaseTransactionsManager $manager, array $names = [null]): void
    {
        $database = m::mock(DatabaseManager::class);

        foreach ($names as $name) {
            $connection = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => $name ?? 'foo']);
            $database->shouldReceive('connection')->once()->with($name)->andReturn($connection);
        }

        $manager->beginTransactions($database);
    }
}

class TestingDatabaseTransactionsManagerTestObject
{
    public bool $ran = false;

    public int $runs = 0;

    /**
     * Record the callback execution.
     */
    public function handle(): void
    {
        $this->ran = true;
        ++$this->runs;
    }
}
