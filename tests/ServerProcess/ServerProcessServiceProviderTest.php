<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\BeforeMainServerStart;
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
}
