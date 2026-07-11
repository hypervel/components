<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Pool\Pool;
use Hypervel\Tests\Pool\Fixtures\ConstantFrequencyStub;
use Hypervel\Tests\Pool\Fixtures\FrequencyStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

class FrequencyTest extends TestCase
{
    public function testFrequencyHit(): void
    {
        $frequency = new FrequencyStub;
        $now = time();
        $frequency->setBeginTime($now - 4);
        $frequency->setHits([
            $now => 1,
            $now - 1 => 10,
            $now - 2 => 10,
            $now - 3 => 10,
            $now - 4 => 10,
        ]);

        $num = $frequency->frequency();
        $this->assertSame(41 / 5, $num);

        $frequency->hit();
        $num = $frequency->frequency();
        $this->assertSame(42 / 5, $num);
    }

    public function testConstantFrequency(): void
    {
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('checkIdleConnection')->atLeast()->once();

        $stub = new ConstantFrequencyStub($pool);
        Coroutine::sleep(0.005);
        $stub->clear();
    }

    public function testFrequencyHitOneSecondAfter(): void
    {
        $frequency = new FrequencyStub;
        $now = time();

        $frequency->setBeginTime($now - 4);
        $frequency->setHits([
            $now => 1,
            $now - 1 => 10,
            $now - 2 => 10,
            $now - 4 => 10,
        ]);
        $num = $frequency->frequency();
        $this->assertSame(31 / 5, $num);
        $frequency->hit();
        $num = $frequency->frequency();
        $this->assertSame(32 / 5, $num);

        $frequency->setHits([
            $now => 1,
            $now - 1 => 10,
            $now - 2 => 10,
            $now - 3 => 10,
        ]);
        $num = $frequency->frequency();
        $this->assertSame(31 / 5, $num);
        $frequency->hit();
        $num = $frequency->frequency();
        $this->assertSame(32 / 5, $num);
    }
}
