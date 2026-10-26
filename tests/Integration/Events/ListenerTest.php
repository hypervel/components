<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Events\ListenerTest;

use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class ListenerTest extends TestCase
{
    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        ListenerTestListener::$ran = false;
        ListenerTestListenerAfterCommit::$ran = false;

        parent::tearDown();
    }

    public function testClassListenerRunsImmediatelyInsideTransaction(): void
    {
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);

        Event::listen(ListenerTestEvent::class, ListenerTestListener::class);

        $manager->begin('default', 1);

        Event::dispatch(new ListenerTestEvent);

        $this->assertTrue(ListenerTestListener::$ran);
    }

    public function testClassListenerDoesntRunInsideTransaction(): void
    {
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);

        Event::listen(ListenerTestEvent::class, ListenerTestListenerAfterCommit::class);

        $manager->begin('default', 1);

        Event::dispatch(new ListenerTestEvent);

        $this->assertFalse(ListenerTestListenerAfterCommit::$ran);

        $manager->commit('default', 1, 0);

        $this->assertTrue(ListenerTestListenerAfterCommit::$ran);
    }

    public function testAfterCommitListenersContinueAfterSiblingFailure(): void
    {
        $transactionManager = new DatabaseTransactionsManager;
        $failure = new RuntimeException('first listener failed');
        $first = new ListenerTestFailingListenerAfterCommit($failure);
        $second = new ListenerTestFollowingListenerAfterCommit;

        $this->app->instance('db.transactions', $transactionManager);
        $this->app->instance(ListenerTestFailingListenerAfterCommit::class, $first);
        $this->app->instance(ListenerTestFollowingListenerAfterCommit::class, $second);

        Event::listen(ListenerTestEvent::class, ListenerTestFailingListenerAfterCommit::class);
        Event::listen(ListenerTestEvent::class, ListenerTestFollowingListenerAfterCommit::class);

        $transactionManager->begin('default', 1);
        Event::dispatch(new ListenerTestEvent);

        $this->assertFalse($first->ran);
        $this->assertFalse($second->ran);

        $caught = null;

        try {
            $transactionManager->commit('default', 1, 0);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertTrue($first->ran);
        $this->assertTrue($second->ran);
    }
}

class ListenerTestEvent
{
}

class ListenerTestListener
{
    public static bool $ran = false;

    /**
     * Handle the event.
     */
    public function handle(): void
    {
        static::$ran = true;
    }
}

class ListenerTestListenerAfterCommit
{
    public static bool $ran = false;

    public bool $afterCommit = true;

    /**
     * Handle the event after commit.
     */
    public function handle(): void
    {
        static::$ran = true;
    }
}

class ListenerTestFailingListenerAfterCommit
{
    public bool $afterCommit = true;

    public bool $ran = false;

    /**
     * Create a listener that throws the given failure.
     */
    public function __construct(
        protected RuntimeException $failure,
    ) {
    }

    /**
     * Fail while handling the event.
     */
    public function handle(): void
    {
        $this->ran = true;

        throw $this->failure;
    }
}

class ListenerTestFollowingListenerAfterCommit
{
    public bool $afterCommit = true;

    public bool $ran = false;

    /**
     * Handle the event after commit.
     */
    public function handle(): void
    {
        $this->ran = true;
    }
}
