<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Support\Manager;
use Hypervel\Tests\TestCase;

class RebindsCallbacksToSelfTest extends TestCase
{
    public function testManagerRebindsAnonymousCallbacksToItself(): void
    {
        $manager = $this->createManager();

        $manager->extend('anonymous', function (): object {
            return $this;
        });

        $this->assertSame($manager, $manager->driver('anonymous'));
    }

    public function testManagerSupportsStaticAnonymousCallbacks(): void
    {
        $manager = $this->createManager();

        $manager->extend('static', static fn (ContainerContract $container): object => $container);

        $this->assertSame($manager->getContainer(), $manager->driver('static'));
    }

    public function testManagerPreservesFirstClassCallableReceiver(): void
    {
        $manager = $this->createManager();
        $creator = new RebindingManagerCreator;

        $manager->extend('first-class', $creator->create(...));

        $this->assertSame($creator, $manager->driver('first-class'));
    }

    protected function createManager(): RebindingManager
    {
        $container = new Container;
        $container->instance('config', new Repository);

        return new RebindingManager($container);
    }
}

class RebindingManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'anonymous';
    }
}

class RebindingManagerCreator
{
    public function create(): object
    {
        return $this;
    }
}
