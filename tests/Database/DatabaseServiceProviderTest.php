<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Queue\EntityResolver;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Database\DatabaseServiceProvider;
use Hypervel\Database\Eloquent\QueueEntityResolver;
use Hypervel\Events\Dispatcher;
use Hypervel\Testbench\TestCase;
use Swoole\Constant;

class DatabaseServiceProviderTest extends TestCase
{
    public function testQueueEntityResolverIsRegistered(): void
    {
        $this->assertInstanceOf(
            QueueEntityResolver::class,
            $this->app->make(EntityResolver::class),
        );
    }

    public function testNonCoroutineTaskLifecycleListenersAreRegistered(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(false);

        $this->assertTrue($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    public function testCoroutineTasksDoNotRegisterTerminalTaskCleanup(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(true);

        $this->assertFalse($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    /**
     * Boot a fresh provider against an isolated event dispatcher.
     */
    private function bootProviderWithTaskCoroutines(bool $enabled): Dispatcher
    {
        $this->app->make('config')->set(
            'server.settings.' . Constant::OPTION_TASK_ENABLE_COROUTINE,
            $enabled,
        );

        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);

        (new DatabaseServiceProvider($this->app))->boot();

        return $events;
    }
}
