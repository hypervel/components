<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\Listeners\StartRecycler;
use Hypervel\ObjectPool\ObjectPoolServiceProvider;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolRecycler;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use stdClass;
use Swoole\Server;

class ObjectPoolServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ObjectPoolServiceProvider::class];
    }

    public function testConcreteManagerAndFactoryShareOnePoolRegistry(): void
    {
        $manager = $this->app->make(PoolManager::class);
        $pool = $manager->pool('shared', static fn () => new stdClass);

        $this->assertSame($manager, $this->app->make(Factory::class));
        $this->assertSame($pool, $this->app->make(Factory::class)->get('shared'));
    }

    public function testConcreteRecyclerAndContractShareOneTimerOwner(): void
    {
        $recycler = $this->app->make(PoolRecycler::class);
        $recycler->setInterval(2.5);

        $this->assertSame($recycler, $this->app->make(Recycler::class));
        $this->assertSame(2.5, $this->app->make(Recycler::class)->getInterval());
    }

    public function testLifecycleFlushesResolvedMasterPoolsBeforeForkAndStartsTheWorkerRecycler(): void
    {
        $listeners = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->twice()
            ->andReturnUsing(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $manager = m::mock(PoolManager::class);
        $manager->shouldReceive('flush')->once();
        $recycler = m::mock(StartRecycler::class);
        $server = m::mock(Server::class);
        $afterWorkerStart = new AfterWorkerStart($server, 0);
        $recycler->shouldReceive('handle')->once()->with($afterWorkerStart);
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('resolved')->once()->with(PoolManager::class)->andReturnTrue();
        $application->shouldReceive('make')->once()->with(PoolManager::class)->andReturn($manager);
        $application->shouldReceive('make')->once()->with(StartRecycler::class)->andReturn($recycler);

        (new ObjectPoolServiceProvider($application))->boot();

        $listeners[BeforeServerFork::class](new BeforeServerFork($server));
        $listeners[AfterWorkerStart::class]($afterWorkerStart);
    }

    public function testBeforeForkDoesNotResolveAnUnusedPoolManager(): void
    {
        $listeners = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->twice()
            ->andReturnUsing(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('resolved')->once()->with(PoolManager::class)->andReturnFalse();

        (new ObjectPoolServiceProvider($application))->boot();

        $listeners[BeforeServerFork::class](new BeforeServerFork(m::mock(Server::class)));
    }
}
