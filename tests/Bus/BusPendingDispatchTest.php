<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Queue\PreparesForDispatch;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;
use stdClass;

class PendingDispatchWithoutDestructor extends PendingDispatch
{
    public function __destruct()
    {
        // Prevent the job from being dispatched
    }
}

class BusPendingDispatchTest extends TestCase
{
    protected $job;

    /**
     * @var PendingDispatchWithoutDestructor
     */
    protected $pendingDispatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->job = m::mock(stdClass::class);
        $this->pendingDispatch = new PendingDispatchWithoutDestructor($this->job);
    }

    public function testOnConnection()
    {
        $this->job->shouldReceive('onConnection')->once()->with('test-connection');
        $this->pendingDispatch->onConnection('test-connection');
    }

    public function testOnQueue()
    {
        $this->job->shouldReceive('onQueue')->once()->with('test-queue');
        $this->pendingDispatch->onQueue('test-queue');
    }

    public function testConditionableCanConfigurePendingDispatch(): void
    {
        $this->job->shouldReceive('onQueue')->once()->with('conditional-queue');

        $this->pendingDispatch->when(true, fn ($pendingDispatch) => $pendingDispatch->onQueue('conditional-queue'));
    }

    public function testOnGroup()
    {
        $this->job->shouldReceive('onGroup')->once()->with('test-group');
        $this->pendingDispatch->onGroup('test-group');
    }

    public function testWithDeduplicator()
    {
        $deduplicator = fn () => 'id';
        $this->job->shouldReceive('withDeduplicator')->once()->with($deduplicator);
        $this->pendingDispatch->withDeduplicator($deduplicator);
    }

    public function testAllOnConnection()
    {
        $this->job->shouldReceive('allOnConnection')->once()->with('test-connection');
        $this->pendingDispatch->allOnConnection('test-connection');
    }

    public function testAllOnQueue()
    {
        $this->job->shouldReceive('allOnQueue')->once()->with('test-queue');
        $this->pendingDispatch->allOnQueue('test-queue');
    }

    public function testDelay()
    {
        $this->job->shouldReceive('delay')->once()->with(60);
        $this->pendingDispatch->delay(60);
    }

    public function testWithoutDelay()
    {
        $this->job->shouldReceive('withoutDelay')->once();
        $this->pendingDispatch->withoutDelay();
    }

    public function testAfterCommit()
    {
        $this->job->shouldReceive('afterCommit')->once();
        $this->pendingDispatch->afterCommit();
    }

    public function testBeforeCommit()
    {
        $this->job->shouldReceive('beforeCommit')->once();
        $this->pendingDispatch->beforeCommit();
    }

    public function testChain()
    {
        $chain = [new stdClass];
        $this->job->shouldReceive('chain')->once()->with($chain);
        $this->pendingDispatch->chain($chain);
    }

    public function testAfterResponse()
    {
        $this->pendingDispatch->afterResponse();
        $this->assertTrue(
            (new ReflectionClass($this->pendingDispatch))->getProperty('afterResponse')->getValue($this->pendingDispatch)
        );
    }

    public function testGetJob()
    {
        $this->assertSame($this->job, $this->pendingDispatch->getJob());
    }

    public function testPrepareForDispatchCanAbortDispatchBeforeDebounceCacheIsResolved(): void
    {
        Container::setInstance($container = new Container);

        try {
            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')->never();
            $dispatcher->shouldReceive('dispatchAfterResponse')->never();
            $container->instance(Dispatcher::class, $dispatcher);

            $job = new PreparingDebouncedPendingDispatchJob(false);
            $pendingDispatch = new PendingDispatch($job);
            unset($pendingDispatch);

            $this->assertSame('', $job->debounceOwner);
        } finally {
            Container::setInstance(null);
        }
    }

    public function testPrepareForDispatchAllowsDispatch(): void
    {
        Container::setInstance($container = new Container);

        try {
            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')->once()->with(m::type(PreparingPendingDispatchJob::class));
            $dispatcher->shouldReceive('dispatchAfterResponse')->never();
            $container->instance(Dispatcher::class, $dispatcher);

            $pendingDispatch = new PendingDispatch(new PreparingPendingDispatchJob(true));
            unset($pendingDispatch);
        } finally {
            Container::setInstance(null);
        }
    }

    public function testDynamicallyProxyMethods()
    {
        $newJob = m::mock(stdClass::class);
        $this->job->shouldReceive('appendToChain')->once()->with($newJob);
        $this->pendingDispatch->appendToChain($newJob);
    }
}

class PreparingPendingDispatchJob implements PreparesForDispatch
{
    public function __construct(
        protected bool $shouldDispatch
    ) {
    }

    public function prepareForDispatch(): bool
    {
        return $this->shouldDispatch;
    }
}

#[DebounceFor(30)]
class PreparingDebouncedPendingDispatchJob extends PreparingPendingDispatchJob
{
    use Queueable;
}
