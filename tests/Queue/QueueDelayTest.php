<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\PendingDispatch;
use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class QueueDelayTest extends TestCase
{
    public function testQueueDelay()
    {
        $this->mockContainer();

        new PendingDispatch($job = new TestJob());

        $this->assertEquals(60, $job->delay);
    }

    public function testQueueWithoutDelay()
    {
        $this->mockContainer();

        $job = new TestJob();

        dispatch($job->withoutDelay());

        $this->assertEquals(0, $job->delay);
    }

    public function testPendingDispatchWithoutDelay()
    {
        $this->mockContainer();

        $job = new TestJob();

        dispatch($job)->withoutDelay();

        $this->assertEquals(0, $job->delay);
    }

    protected function mockContainer(): void
    {
        $event = m::mock(Dispatcher::class);
        $event->shouldReceive('dispatch');
        $container = new Container();
        $container->instance(Dispatcher::class, $event);

        ApplicationContext::setContainer($container);
    }
}

class TestJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->delay(60);
    }
}
