<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber as FinishedSubscriberContract;

class TestFinishedSubscriber implements FinishedSubscriberContract
{
    /**
     * Create a new test finished subscriber.
     */
    public function __construct(
        protected ProfileTracker $tracker,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(Finished $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->stop($event->test()->id(), $event->test()->id(), $time);
    }
}
