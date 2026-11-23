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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Swoole\Server as SwooleServer;

class RateLimiterServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testRateLimiterIsARequiredFrameworkProvider(): void
    {
        $this->assertContains(
            RateLimiterServiceProvider::class,
            (new DefaultProviders)->toArray(),
        );
    }

    public function testLifecycleListenersAreResolvedAtEventTime(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $listeners = [];
        $events->expects('listen')
            ->times(2)
            ->resolves(function (mixed $event, mixed $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $tables = Double::for(InitializeSwooleTables::class)->strict();
        $timers = Double::for(RegisterPruneTimer::class)->strict();
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')->with('events')->returns($events);
        $application->expects('make')->with(InitializeSwooleTables::class)->returns($tables);
        $application->expects('make')->with(RegisterPruneTimer::class)->returns($timers);
        $beforeServerStart = new BeforeServerStart('server');
        $server = Double::for(SwooleServer::class)->strict();
        $afterWorkerStart = new AfterWorkerStart($server, 0);
        $tables->expects('handle')->with(Argument::same($beforeServerStart));
        $timers->expects('handle')->with(Argument::same($afterWorkerStart));

        (new RateLimiterServiceProvider($application->instance()))->boot();

        $listeners[BeforeServerStart::class]($beforeServerStart);
        $listeners[AfterWorkerStart::class]($afterWorkerStart);
    }
}
