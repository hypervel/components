<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Pool\SimplePool\Pool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class SimplePoolTest extends TestCase
{
    public function testCallbackConnectionIsReusedAndRecordsMonotonicActivityTime(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturnFalse();
        $pool = new Pool($container, 'simple', static fn (): stdClass => new stdClass, []);

        try {
            $firstLease = $pool->get();

            try {
                $before = hrtime(true) / 1e9;
                $activeConnection = $firstLease->getConnection();
                $after = hrtime(true) / 1e9;

                $this->assertInstanceOf(stdClass::class, $activeConnection);
                $this->assertGreaterThanOrEqual($before, $firstLease->getLastUseTime());
                $this->assertLessThanOrEqual($after, $firstLease->getLastUseTime());
            } finally {
                $firstLease->release();
            }

            $secondLease = $pool->get();

            try {
                $this->assertSame($activeConnection, $secondLease->getConnection());
            } finally {
                $secondLease->release();
            }
        } finally {
            $pool->close();
        }
    }
}
