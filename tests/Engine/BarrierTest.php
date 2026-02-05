<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Barrier;
use Hypervel\Engine\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class BarrierTest extends EngineTestCase
{
    public function testBarrier()
    {
        $barrier = Barrier::create();
        $N = 10;
        $count = 0;
        for ($i = 0; $i < $N; ++$i) {
            Coroutine::create(function () use (&$count, $barrier) {
                isset($barrier);
                usleep(2000);
                ++$count;
            });
        }
        Barrier::wait($barrier);
        $this->assertSame($N, $count);
    }
}
