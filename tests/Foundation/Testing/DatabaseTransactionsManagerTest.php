<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Foundation\Testing\DatabaseTransactionsManager;
use Hypervel\Tests\TestCase;

class DatabaseTransactionsManagerTest extends TestCase
{
    public function testItExecutesCallbacksImmediatelyIfThereIsOnlyOneTransaction()
    {
        $testObject = new TestingDatabaseTransactionsManagerTestObject;
        $manager = new DatabaseTransactionsManager([null]);

        $manager->begin('foo', 1);

        $manager->addCallback(fn () => $testObject->handle());

        $this->assertTrue($testObject->ran);
        $this->assertEquals(1, $testObject->runs);
    }

    public function testItIgnoresTheBaseTransactionForCallbackApplicableTransactions()
    {
        $manager = new DatabaseTransactionsManager([null]);

        $manager->begin('foo', 1);
        $manager->begin('foo', 2);

        $this->assertCount(1, $manager->callbackApplicableTransactions());
        $this->assertEquals(2, $manager->callbackApplicableTransactions()[0]->level);
    }

    public function testCommittingDoesNotRemoveTheBasePendingTransaction()
    {
        $manager = new DatabaseTransactionsManager([null]);

        $manager->begin('foo', 1);

        $manager->begin('foo', 2);
        $manager->commit('foo', 2, 1);

        $this->assertCount(0, $manager->callbackApplicableTransactions());

        $manager->begin('foo', 2);

        $this->assertCount(1, $manager->callbackApplicableTransactions());
        $this->assertEquals(2, $manager->callbackApplicableTransactions()[0]->level);
    }

    public function testItExecutesCallbacksForTheSecondTransaction()
    {
        $testObject = new TestingDatabaseTransactionsManagerTestObject;
        $manager = new DatabaseTransactionsManager([null]);
        $manager->begin('foo', 1);
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

        $manager->begin('default', 1);
        $manager->begin('admin', 1);
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

    public function testItExecutesTransactionCallbacksAtLevelOne()
    {
        $manager = new DatabaseTransactionsManager([null]);

        $this->assertFalse($manager->afterCommitCallbacksShouldBeExecuted(0));
        $this->assertTrue($manager->afterCommitCallbacksShouldBeExecuted(1));
        $this->assertFalse($manager->afterCommitCallbacksShouldBeExecuted(2));
    }

    public function testSkipsTheNumberOfConnectionsTransacting()
    {
        $manager = new DatabaseTransactionsManager([null]);

        $manager->begin('foo', 1);
        $manager->begin('foo', 2);

        $this->assertCount(1, $manager->callbackApplicableTransactions());
    }
}

class TestingDatabaseTransactionsManagerTestObject
{
    public bool $ran = false;

    public int $runs = 0;

    public function handle()
    {
        $this->ran = true;
        ++$this->runs;
    }
}
