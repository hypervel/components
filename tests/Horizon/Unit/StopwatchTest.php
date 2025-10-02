<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Stopwatch;
use Hypervel\Tests\Horizon\UnitTestCase;

/**
 * @internal
 * @coversNothing
 */
class StopwatchTest extends UnitTestCase
{
    public function testTimeBetweenChecksCanBeMeasured()
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('foo');
        usleep(10 * 1000);
        $difference = $stopwatch->check('foo');

        // Make sure the millisecond difference is within a normal range of variance...
        $this->assertGreaterThan(0, $difference);
    }
}
