<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\BootProviders;
use Hypervel\Foundation\Console\Kernel;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class KernelTest extends TestCase
{
    public function testHandleCatchesExceptionsAndReturnsOne()
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();
        $handler->shouldReceive('renderForConsole')->once();
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events')) extends Kernel {
            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                // Throw during bootstrap to trigger the catch block.
                throw new RuntimeException('Bootstrap failed');
            }
        };

        $result = $kernel->handle(new StringInput(''), new BufferedOutput);

        $this->assertSame(1, $result);
    }

    public function testHandleRetainsTheOriginalExceptionWhenReportingFails(): void
    {
        $original = new RuntimeException('Bootstrap failed');
        $reportingFailure = new RuntimeException('Reporting failed');

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($original)->andThrow($reportingFailure);
        $handler->shouldNotReceive('renderForConsole');
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events'), $original) extends Kernel {
            public function __construct(Application $app, Dispatcher $events, private readonly RuntimeException $exception)
            {
                parent::__construct($app, $events);
            }

            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                throw $this->exception;
            }
        };

        try {
            $kernel->handle(new StringInput(''), new BufferedOutput);
            $this->fail('Expected exception reporting to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    public function testBootstrapWithoutBootingProvidersSkipsBootProviders()
    {
        $bootstrappedWith = null;

        $kernel = $this->app->make(KernelContract::class);

        // Replace the app with a spy that captures what bootstrappers are used.
        $app = m::mock($this->app)->makePartial();
        $app->shouldReceive('bootstrapWith')->once()->with(m::on(function (array $bootstrappers) use (&$bootstrappedWith) {
            $bootstrappedWith = $bootstrappers;
            return true;
        }));

        // Use reflection to replace the app on the kernel.
        $reflection = new ReflectionProperty($kernel, 'app');
        $reflection->setValue($kernel, $app);

        $kernel->bootstrapWithoutBootingProviders();

        $this->assertNotNull($bootstrappedWith);
        $this->assertNotContains(BootProviders::class, $bootstrappedWith);
    }

    public function testReportExceptionDelegatesToExceptionHandler()
    {
        $exception = new RuntimeException('Test exception');

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new Kernel($this->app, $this->app->make('events'));

        $method = new ReflectionMethod($kernel, 'reportException');
        $method->invoke($kernel, $exception);
    }

    public function testRenderExceptionDelegatesToExceptionHandler()
    {
        $exception = new RuntimeException('Test exception');
        $output = new BufferedOutput;

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('renderForConsole')->once()->with($output, $exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new Kernel($this->app, $this->app->make('events'));

        $method = new ReflectionMethod($kernel, 'renderException');
        $method->invoke($kernel, $output, $exception);
    }

    public function testRenderThrowableUsesConsoleErrorOutput(): void
    {
        $exception = new RuntimeException('Test exception');
        $output = new ConsoleOutput;
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('renderForConsole')->once()->with($errorOutput, $exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new Kernel($this->app, $this->app->make('events'));

        $method = new ReflectionMethod($kernel, 'renderException');
        $method->invoke($kernel, $output, $exception);
    }

    public function testHandleWithNullOutputPreservesOriginalThrowable(): void
    {
        $reportedException = null;

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->andReturnUsing(function (RuntimeException $exception) use (&$reportedException): void {
            $reportedException = $exception;
        });
        $handler->shouldReceive('renderForConsole')->once()->with(
            m::on(fn (OutputInterface $output): bool => ! $output instanceof ConsoleOutputInterface),
            m::on(function (RuntimeException $exception) use (&$reportedException): bool {
                return $exception === $reportedException && $exception->getMessage() === 'Bootstrap failed';
            }),
        );
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events')) extends Kernel {
            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                throw new RuntimeException('Bootstrap failed');
            }
        };

        $this->assertSame(1, $kernel->handle(new StringInput('')));
    }

    public function testItDispatchesTerminatingEvent()
    {
        $called = [];
        $app = new Application;
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $kernel = new Kernel($app, $events);
        $events->listen(function (Terminating $terminating) use (&$called) {
            $called[] = 'terminating event';
        });
        $app->terminating(function () use (&$called) {
            $called[] = 'terminating callback';
        });

        $kernel->terminate(new StringInput('tinker'), 0);

        $this->assertSame([
            'terminating event',
            'terminating callback',
        ], $called);
    }
}
