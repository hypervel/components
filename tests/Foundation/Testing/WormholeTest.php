<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Carbon\Carbon;
use Hypervel\Foundation\Testing\Wormhole;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class WormholeTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow();
    }

    public function testCanTravelBackToPresent()
    {
        // Preserve the timelines we want to compare the reality with...
        $present = now();
        $future = now()->addDays(10);

        // Travel in time...
        (new Wormhole(10))->days();

        // Assert we are now in the future...
        $this->assertEquals($future->format('Y-m-d'), now()->format('Y-m-d'));

        // Assert we can go back to the present...
        $this->assertEquals($present->format('Y-m-d'), Wormhole::back()->format('Y-m-d'));
    }

    public function testItCanTravelByMicroseconds()
    {
        Carbon::setTestNow(Carbon::parse('2000-01-01 00:00:00')->startOfSecond());

        (new Wormhole(1))->microsecond();
        $this->assertSame('2000-01-01 00:00:00.000001', Carbon::now()->format('Y-m-d H:i:s.u'));

        (new Wormhole(5))->microseconds();
        $this->assertSame('2000-01-01 00:00:00.000006', Carbon::now()->format('Y-m-d H:i:s.u'));

        Carbon::setTestnow();
    }
}
