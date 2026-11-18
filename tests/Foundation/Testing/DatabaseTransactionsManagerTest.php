<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Coroutine\Waiter;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Foundation\Testing\DatabaseTransactionsManager;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;

class DatabaseTransactionsManagerTest extends TestCase
{
    use VerifiesDoubles;

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

    public function testRoleWrappersExcludeOnlyTheirResolvedOwnersTransaction(): void
    {
        $manager = new DatabaseTransactionsManager(['foo::write']);
        $database = Double::for(DatabaseManager::class)->strict();
        $writer = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => 'foo']);
        $writer->setReadWriteType('write');
        $database->expects('connection')->with('foo::write')->returns($writer);
        $manager->beginTransactions($database);

        $this->assertFalse($writer->hasApplicableTransaction());

        $base = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => 'foo']);
        $base->setTransactionManager($manager);
        $base->beginTransaction();
        $this->assertTrue($base->hasApplicableTransaction());

        $called = false;
        $writer->transaction(function () use ($writer, &$called): void {
            $writer->afterCommit(function () use (&$called): void {
                $called = true;
            });
        });

        $this->assertTrue($called);
        $this->assertTrue($base->hasApplicableTransaction());
        $this->assertFalse($writer->hasApplicableTransaction());

        $base->rollBack();
        $writer->rollBack();
        $this->assertTrue($manager->getPendingTransactions()->isEmpty());
    }

    /**
     * Begin the real wrappers while keeping these manager tests independent of an application.
     */
    protected function beginWrappingTransactions(DatabaseTransactionsManager $manager, array $names = [null]): void
    {
        $database = Double::for(DatabaseManager::class)->strict();

        foreach ($names as $name) {
            $connection = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => $name ?? 'foo']);
            $database->expects('connection')->with($name)->returns($connection);
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
