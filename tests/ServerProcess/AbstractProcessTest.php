<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Event\Dispatcher as DispatcherContract;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\ProcessCollector;
use Hypervel\Tests\ServerProcess\Stub\FooProcess;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;
use RuntimeException;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class AbstractProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset ProcessCollector static state
        $ref = new ReflectionClass(ProcessCollector::class);
        $prop = $ref->getProperty('processes');
        $prop->setValue(null, []);

        FooProcess::$handled = false;
    }

    public function testIsEnableReturnsTrueByDefault()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);

        $process = new FooProcess($container);
        $server = m::mock(Server::class);

        $this->assertTrue($process->isEnable($server));
    }

    public function testDefaultPropertyValues()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);

        $process = new FooProcess($container);

        $this->assertSame('process', $process->name);
        $this->assertSame(1, $process->nums);
        $this->assertFalse($process->redirectStdinStdout);
        $this->assertSame(SOCK_DGRAM, $process->pipeType);
    }

    public function testBindCreatesProcessAndAddsToServer()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(false);

        $process = new FooProcess($container);

        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->once()->andReturnUsing(function ($swooleProcess) {
            // Execute the callback to verify it runs
            $ref = new ReflectionClass($swooleProcess);
            $property = $ref->getProperty('callback');
            $callback = $property->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);

        $this->assertTrue(FooProcess::$handled);
    }

    public function testBindCreatesMultipleProcessesWhenNumsGreaterThanOne()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(false);

        $process = new FooProcess($container);
        $process->nums = 3;

        $addCount = 0;
        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->times(3)->andReturnUsing(function () use (&$addCount) {
            return ++$addCount;
        });

        $process->bind($server);

        $this->assertSame(3, $addCount);
    }

    public function testBindDispatchesBeforeAndAfterEvents()
    {
        $dispatched = [];
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(DispatcherContract::class)->andReturn($dispatcher);

        $process = new FooProcess($container);

        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->andReturnUsing(function ($swooleProcess) {
            $ref = new ReflectionClass($swooleProcess);
            $callback = $ref->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);

        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(BeforeProcessHandle::class, $dispatched[0]);
        $this->assertInstanceOf(AfterProcessHandle::class, $dispatched[1]);
        $this->assertSame($process, $dispatched[0]->process);
        $this->assertSame(0, $dispatched[0]->index);
    }

    public function testBindDispatchesEventsWithCorrectIndices()
    {
        $dispatched = [];
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(DispatcherContract::class)->andReturn($dispatcher);

        $process = new FooProcess($container);
        $process->nums = 2;

        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->andReturnUsing(function ($swooleProcess) {
            $ref = new ReflectionClass($swooleProcess);
            $callback = $ref->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);

        // 2 processes × 2 events (before+after) = 4 events
        $this->assertCount(4, $dispatched);
        $this->assertSame(0, $dispatched[0]->index); // Before process 0
        $this->assertSame(0, $dispatched[1]->index); // After process 0
        $this->assertSame(1, $dispatched[2]->index); // Before process 1
        $this->assertSame(1, $dispatched[3]->index); // After process 1
    }

    public function testLogThrowableReportsViaExceptionHandler()
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(false);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $process = new class($container) extends AbstractProcess {
            public bool $enableCoroutine = false;

            public int $restartInterval = 0;

            public function handle(): void
            {
                throw new RuntimeException('test error');
            }
        };

        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->andReturnUsing(function ($swooleProcess) {
            $ref = new ReflectionClass($swooleProcess);
            $callback = $ref->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);
    }

    public function testLogThrowableSilentlyIgnoresWhenNoExceptionHandler()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(false);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(false);

        $process = new class($container) extends AbstractProcess {
            public bool $enableCoroutine = false;

            public int $restartInterval = 0;

            public function handle(): void
            {
                throw new RuntimeException('test error');
            }
        };

        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->andReturnUsing(function ($swooleProcess) {
            $ref = new ReflectionClass($swooleProcess);
            $callback = $ref->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        // Should not throw — the exception is caught and silently ignored
        $process->bind($server);
        $this->assertTrue(true);
    }

    public function testConstructorResolvesEventDispatcherIfAvailable()
    {
        $dispatcher = m::mock(DispatcherContract::class);
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(DispatcherContract::class)->andReturn($dispatcher);

        $process = new FooProcess($container);

        $ref = new ReflectionClass($process);
        $prop = $ref->getProperty('event');
        $this->assertSame($dispatcher, $prop->getValue($process));
    }

    public function testConstructorSetsEventToNullWhenNotAvailable()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(false);

        $process = new FooProcess($container);

        $ref = new ReflectionClass($process);
        $prop = $ref->getProperty('event');
        $this->assertNull($prop->getValue($process));
    }
}
