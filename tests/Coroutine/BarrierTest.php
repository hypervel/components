<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Barrier;
use Hypervel\Coroutine\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class BarrierTest extends CoroutineTestCase
{
    public function testBarrier(): void
    {
        $barrier = Barrier::create();
        $N = 10;
        $count = 0;
        for ($i = 0; $i < $N; ++$i) {
            Coroutine::create(function () use (&$count, $barrier) {
                isset($barrier);
                sleep(1);
                ++$count;
            });
        }
        Barrier::wait($barrier);
        $this->assertSame($N, $count);
    }
}
