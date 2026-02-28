<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Pool\Channel;
use Hypervel\Pool\Pool;
use Hypervel\Tests\Pool\Stub\ConstantFrequencyStub;
use Hypervel\Tests\Pool\Stub\FrequencyStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class FrequencyTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testFrequencyHit()
    {
        $frequency = new FrequencyStub();
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

    public function testConstantFrequency()
    {
        $pool = m::mock(Pool::class);
        $channel = new Channel(100);
        $pool->shouldReceive('flushOne')->andReturnUsing(function () use ($channel) {
            $channel->push(m::mock(ConnectionInterface::class));
        });

        $stub = new ConstantFrequencyStub($pool);
        Coroutine::sleep(0.005);
        $stub->clear();
        $this->assertGreaterThan(0, $channel->length());
    }

    public function testFrequencyHitOneSecondAfter()
    {
        $frequency = new FrequencyStub();
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
