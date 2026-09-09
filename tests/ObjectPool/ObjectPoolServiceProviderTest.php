<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\Contracts\ObjectPool\Recycler;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\ObjectPool\Listeners\StartRecycler;
use Hypervel\ObjectPool\ObjectPoolServiceProvider;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
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

        $this->assertSame($recycler, $this->app->make(Recycler::class));
        $this->assertSame(10.0, $recycler->getInterval());
    }

    public function testRecyclerConstructionCanBeCustomizedThroughItsBinding(): void
    {
        $this->app->singleton(PoolRecycler::class, fn ($app) => new PoolRecycler(
            $app->make(Factory::class),
            interval: 2.5,
        ));

        $recycler = $this->app->make(Recycler::class);

        $this->assertInstanceOf(PoolRecycler::class, $recycler);
        $this->assertSame(2.5, $recycler->getInterval());
        $this->assertSame($recycler, $this->app->make(PoolRecycler::class));
    }

    public function testShippedPoolConfigurationMatchesNormalizedDefaults(): void
    {
        $defaults = PoolOptions::fromArray([])->toArray();

        foreach ([
            'filesystems.disks.s3.pool',
            'filesystems.disks.gcs.pool',
            'queue.connections.beanstalkd.pool',
            'queue.connections.sqs.pool',
        ] as $key) {
            $this->assertSame($defaults, config($key), $key);
        }
    }

    public function testLifecyclePurgesResolvedMasterPoolsBeforeForkAndStartsTheWorkerRecycler(): void
    {
        $listeners = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->twice()
            ->andReturnUsing(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $manager = m::mock(PoolManager::class);
        $manager->shouldReceive('purgeAll')->once();
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
