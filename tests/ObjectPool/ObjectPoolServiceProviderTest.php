<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\ObjectPoolServiceProvider;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolRecycler;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Swoole\Server;
use stdClass;

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

    public function testWorkerExitDoesNotResolveAnUnusedManager(): void
    {
        $this->assertFalse($this->app->resolved(PoolManager::class));

        $this->app->make('events')->dispatch(new OnWorkerExit(m::mock(Server::class), 0));

        $this->assertFalse($this->app->resolved(PoolManager::class));
    }

    public function testWorkerExitFlushesAResolvedManager(): void
    {
        $manager = $this->app->make(Factory::class);
        $this->assertTrue($this->app->resolved(PoolManager::class));

        $pool = $manager->pool('worker-exit', static fn (): object => new stdClass);
        $borrowed = $pool->get();
        $idle = $pool->get();
        $pool->release($idle);

        $this->app->make('events')->dispatch(new OnWorkerExit(m::mock(Server::class), 0));
        $this->app->make('events')->dispatch(new OnWorkerExit(m::mock(Server::class), 0));

        $this->assertSame([], $manager->pools());
        $this->assertTrue($pool->isClosed());
        $this->assertSame(1, $pool->getCurrentObjectNumber());

        $pool->release($borrowed);

        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }
}
