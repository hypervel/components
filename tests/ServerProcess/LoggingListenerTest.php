<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\Listeners\LogAfterProcessStoppedListener;
use Hypervel\ServerProcess\Listeners\LogBeforeProcessStartListener;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class LoggingListenerTest extends TestCase
{
    public function testLogBeforeProcessStartListenerListensForCorrectEvents()
    {
        $container = m::mock(ContainerContract::class);
        $listener = new LogBeforeProcessStartListener($container);

        $this->assertSame([
            BeforeProcessHandle::class,
        ], $listener->listen());
    }

    public function testLogAfterProcessStoppedListenerListensForCorrectEvents()
    {
        $container = m::mock(ContainerContract::class);
        $listener = new LogAfterProcessStoppedListener($container);

        $this->assertSame([
            AfterProcessHandle::class,
        ], $listener->listen());
    }

    public function testLogBeforeProcessStartLogsViaStdoutLogger()
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('Process[my-worker.2] start.');

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn($logger);

        $listener = new LogBeforeProcessStartListener($container);
        $event = new BeforeProcessHandle($this->createProcess('my-worker'), 2);

        $listener->process($event);
    }

    public function testLogAfterProcessStoppedLogsViaStdoutLogger()
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('Process[scheduler.0] stopped.');

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn($logger);

        $listener = new LogAfterProcessStoppedListener($container);
        $event = new AfterProcessHandle($this->createProcess('scheduler'), 0);

        $listener->process($event);
    }

    public function testLogBeforeProcessStartFallsBackToEchoWhenNoLogger()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(false);

        $listener = new LogBeforeProcessStartListener($container);
        $event = new BeforeProcessHandle($this->createProcess('queue'), 1);

        ob_start();
        $listener->process($event);
        $output = ob_get_clean();

        $this->assertSame("Process[queue.1] start.\n", $output);
    }

    public function testLogAfterProcessStoppedFallsBackToEchoWhenNoLogger()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(false);

        $listener = new LogAfterProcessStoppedListener($container);
        $event = new AfterProcessHandle($this->createProcess('queue'), 1);

        ob_start();
        $listener->process($event);
        $output = ob_get_clean();

        $this->assertSame("Process[queue.1] stopped.\n", $output);
    }

    private function createProcess(string $name): AbstractProcess
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);

        $process = new class($container) extends AbstractProcess {
            public function handle(): void
            {
            }
        };
        $process->name = $name;

        return $process;
    }
}
