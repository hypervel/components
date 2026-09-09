<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\CallbackObjectPool;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use stdClass;

class CallbackObjectPoolTest extends TestCase
{
    public function testCreateObject(): void
    {
        $object = new stdClass;
        $pool = new CallbackObjectPool(fn () => $object, PoolOptions::fromArray([]));
        $borrowed = $pool->borrow();

        try {
            $this->assertSame($object, $borrowed);
        } finally {
            $pool->release($borrowed);
            $pool->close();
        }
    }

    public function testDestroyCallbackRunsWhenThePoolCloses(): void
    {
        $destroyed = [];
        $pool = new CallbackObjectPool(
            fn () => new stdClass,
            PoolOptions::fromArray([]),
            function (object $object) use (&$destroyed): void {
                $destroyed[] = $object;
            },
        );
        $object = $pool->borrow();
        $pool->release($object);

        $pool->close();

        $this->assertSame([$object], $destroyed);
    }
}
