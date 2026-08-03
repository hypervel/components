<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use stdClass;

class SimpleObjectPoolTest extends TestCase
{
    public function testCreateObject(): void
    {
        $object = new stdClass;
        $pool = new SimpleObjectPool(fn () => $object, PoolOptions::fromArray([]));
        $borrowed = $pool->get();

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
        $pool = new SimpleObjectPool(
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
}
