<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Events\ListenerTest;

use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        ListenerTestListener::$ran = false;
        ListenerTestListenerAfterCommit::$ran = false;

        parent::tearDown();
    }

    public function testClassListenerRunsNormallyIfNoTransactions()
    {
        $this->app->singleton('db.transactions', function () {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->shouldNotReceive('addCallback')->once()->andReturn(null);

            return $transactionManager;
        });

        Event::listen(ListenerTestEvent::class, ListenerTestListener::class);

        Event::dispatch(new ListenerTestEvent());

        $this->assertTrue(ListenerTestListener::$ran);
    }

    public function testClassListenerDoesntRunInsideTransaction()
    {
        $this->app->singleton('db.transactions', function () {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->shouldReceive('addCallback')->once()->andReturn(null);

            return $transactionManager;
        });

        Event::listen(ListenerTestEvent::class, ListenerTestListenerAfterCommit::class);

        Event::dispatch(new ListenerTestEvent());

        $this->assertFalse(ListenerTestListenerAfterCommit::$ran);
    }
}

class ListenerTestEvent
{
}

class ListenerTestListener
{
    public static bool $ran = false;

    public function handle()
    {
        static::$ran = true;
    }
}

class ListenerTestListenerAfterCommit
{
    public static bool $ran = false;

    public bool $afterCommit = true;

    public function handle()
    {
        static::$ran = true;
    }
}
