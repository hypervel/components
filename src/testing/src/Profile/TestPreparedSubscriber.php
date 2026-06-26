<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber as PreparedSubscriberContract;

class TestPreparedSubscriber implements PreparedSubscriberContract
{
    /**
     * Create a new test prepared subscriber.
     */
    public function __construct(
        protected ProfileTracker $tracker,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(Prepared $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->start($event->test()->id(), $time);
    }
}
