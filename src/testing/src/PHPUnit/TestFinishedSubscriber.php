<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

class TestFinishedSubscriber implements FinishedSubscriber
{
    /**
     * Create a new test finished subscriber.
     */
    public function __construct(
        private SlowTestTracker $tracker,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(Finished $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->endTest($event->test()->id(), $event->test()->id(), $time);
    }
}
