<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\RateLimiter\Listeners\InitializeSwooleTables;
use Hypervel\RateLimiter\Listeners\RegisterPruneTimer;
use Hypervel\RateLimiter\RateLimiterServiceProvider;
use Hypervel\Support\DefaultProviders;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Server as SwooleServer;

class RateLimiterServiceProviderTest extends TestCase
{
    public function testRateLimiterIsARequiredFrameworkProvider(): void
    {
        $this->assertContains(
            RateLimiterServiceProvider::class,
            (new DefaultProviders)->toArray(),
        );
    }

    public function testLifecycleListenersAreResolvedAtEventTime(): void
    {
        $events = m::mock(Dispatcher::class);
        $listeners = [];
        $events->shouldReceive('listen')
            ->twice()
            ->andReturnUsing(function (mixed $event, mixed $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $tables = m::mock(InitializeSwooleTables::class);
        $timers = m::mock(RegisterPruneTimer::class);
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('make')->once()->with(InitializeSwooleTables::class)->andReturn($tables);
        $application->shouldReceive('make')->once()->with(RegisterPruneTimer::class)->andReturn($timers);
        $beforeServerStart = new BeforeServerStart('server');
        $server = m::mock(SwooleServer::class);
        $afterWorkerStart = new AfterWorkerStart($server, 0);
        $tables->shouldReceive('handle')->once()->with($beforeServerStart);
        $timers->shouldReceive('handle')->once()->with($afterWorkerStart);

        (new RateLimiterServiceProvider($application))->boot();

        $listeners[BeforeServerStart::class]($beforeServerStart);
        $listeners[AfterWorkerStart::class]($afterWorkerStart);
    }
}
