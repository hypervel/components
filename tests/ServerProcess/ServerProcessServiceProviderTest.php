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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class ServerProcessServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testLifecycleLoggingIsRegisteredAsPassiveObservation(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->with(BeforeMainServerStart::class, Argument::type(Closure::class));
        $events->expects('observe')
            ->with(AfterProcessHandle::class, Argument::type(Closure::class));
        $events->expects('observe')
            ->with(BeforeProcessHandle::class, Argument::type(Closure::class));

        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')->with('events')->returns($events);

        (new ServerProcessServiceProvider($application->instance()))->boot();
    }

    public function testLifecycleObserversLogDispatchedProcessEvents(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
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
