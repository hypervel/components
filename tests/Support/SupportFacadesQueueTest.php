<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\SupportFacadesQueueTest;

use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Factory as QueueContract;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\Worker;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionProperty;

class SupportFacadesQueueTest extends TestCase
{
    private QueueManager&MockInterface $queueManager;

    /**
     * Set up the queue manager and facade.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->queueManager = m::mock(QueueManager::class);

        $container = new Container;
        $container->instance('queue', $this->queueManager);
        $container->alias('queue', QueueContract::class);

        Facade::setFacadeApplication($container);
    }

    public function testFakeFor(): void
    {
        Queue::fakeFor(function (): void {
            (new QueueForStub)->pushJob();

            Queue::assertPushed(QueueJobStub::class);
        });

        $this->queueManager->expects('push');

        (new QueueForStub)->pushJob();
    }

    public function testFakeForSwapsQueueManager(): void
    {
        Queue::fakeFor(function (): void {
            $this->assertInstanceOf(QueueFake::class, Queue::getFacadeRoot());
        });

        $this->assertSame($this->queueManager, Queue::getFacadeRoot());
    }

    public function testFakeExcept(): void
    {
        $fake = Queue::fakeExcept(QueueJobStub::class);

        $this->assertInstanceOf(QueueFake::class, $fake);
        $this->assertSame($fake, Queue::getFacadeRoot());
    }

    public function testFakeExceptFor(): void
    {
        Queue::fakeExceptFor(function (): void {
            $this->assertInstanceOf(QueueFake::class, Queue::getFacadeRoot());
        }, [QueueJobStub::class]);

        $this->assertSame($this->queueManager, Queue::getFacadeRoot());
    }

    public function testFakeExceptForReturnValue(): void
    {
        $result = Queue::fakeExceptFor(function (): string {
            return 'test-result';
        });

        $this->assertSame('test-result', $result);
    }

    public function testFakeForReturnValue(): void
    {
        $result = Queue::fakeFor(function (): string {
            return 'test-result';
        });

        $this->assertSame('test-result', $result);
    }

    public function testPopUsingRegistersAndRemovesWorkerCallback(): void
    {
        $callbacks = new ReflectionProperty(Worker::class, 'popCallbacks');
        $callback = static fn (): null => null;

        Queue::popUsing('myworker', $callback);

        $this->assertSame(['myworker' => $callback], $callbacks->getValue());

        Queue::popUsing('myworker', null);

        $this->assertSame([], $callbacks->getValue());
    }
}

class QueueJobStub
{
    use Queueable;
}

class QueueForStub
{
    /**
     * Push the test job.
     */
    public function pushJob(): void
    {
        Queue::push(new QueueJobStub);
    }
}
