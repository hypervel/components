<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

class TestFinishedSubscriber implements FinishedSubscriber
{
    private SlowTestTracker $tracker;

    public function __construct(SlowTestTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function notify(Finished $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->endTest($event->test()->id(), $event->test()->id(), $time);
    }
}
