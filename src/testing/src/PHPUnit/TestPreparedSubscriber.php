<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

class TestPreparedSubscriber implements PreparedSubscriber
{
    /**
     * Create a new test prepared subscriber.
     */
    public function __construct(
        private SlowTestTracker $tracker,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(Prepared $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->startTest($event->test()->id(), $time);
    }
}
