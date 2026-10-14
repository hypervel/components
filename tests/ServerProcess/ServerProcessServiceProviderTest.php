<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Application as FoundationApplication;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\ServerProcessServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ServerProcessServiceProviderTest extends TestCase
{
    public function testLifecycleLoggingIsRegisteredAsPassiveObservation(): void
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->with(BeforeMainServerStart::class, m::type(Closure::class))
            ->once();
        $events->shouldReceive('observe')
            ->with(AfterProcessHandle::class, m::type(Closure::class))
            ->once();
        $events->shouldReceive('observe')
            ->with(BeforeProcessHandle::class, m::type(Closure::class))
            ->once();

        $application = m::mock(Application::class);
        $application->shouldReceive('make')->with('events')->once()->andReturn($events);

        (new ServerProcessServiceProvider($application))->boot();
    }

    public function testLifecycleObserversLogDispatchedProcessEvents(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->expects('info')->with('Process[observed.0] start.');
        $logger->expects('info')->with('Process[observed.0] stopped.');

        $application = new FoundationApplication;
        $application->instance(StdoutLoggerInterface::class, $logger);
        $application->instance('events', $events = new EventDispatcher($application));

        (new ServerProcessServiceProvider($application))->boot();

        $process = new class($application) extends AbstractProcess {
            public string $name = 'observed';

            /**
             * Handle the test process.
             */
            public function handle(): void
            {
            }
        };

        $events->dispatch(new BeforeProcessHandle($process, 0));
        $events->dispatch(new AfterProcessHandle($process, 0));
    }
}
