<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

class TestPreparedSubscriber implements PreparedSubscriber
{
    private SlowTestTracker $tracker;

    public function __construct(SlowTestTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function notify(Prepared $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->startTest($event->test()->id(), $time);
    }
}
