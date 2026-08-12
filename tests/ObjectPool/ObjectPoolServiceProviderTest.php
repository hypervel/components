<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\ObjectPoolServiceProvider;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolRecycler;
use Hypervel\Testbench\TestCase;
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
}
