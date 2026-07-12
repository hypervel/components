<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use stdClass;

class SimpleObjectPoolTest extends TestCase
{
    public function testCreateObject(): void
    {
        $container = $this->getContainer();
        $object = new stdClass;
        $pool = new SimpleObjectPool($container, fn () => $object, PoolOptions::fromArray([]));

        $this->assertSame($object, $pool->get());
    }

    public function testDestroyCallbackRunsWhenThePoolCloses(): void
    {
        $destroyed = [];
        $pool = new SimpleObjectPool(
            $this->getContainer(),
            fn () => new stdClass,
            PoolOptions::fromArray([]),
            function (object $object) use (&$destroyed): void {
                $destroyed[] = $object;
            },
        );
        $object = $pool->get();
        $pool->release($object);

        $pool->close();

        $this->assertSame([$object], $destroyed);
    }

    protected function getContainer(): Container
    {
        $container = new Container;
        Container::setInstance($container);

        return $container;
    }
}
