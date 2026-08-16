<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber as PreparationStartedSubscriberContract;

class TestPreparationStartedSubscriber implements PreparationStartedSubscriberContract
{
    /**
     * Create a new test preparation started subscriber.
     */
    public function __construct(
        protected ProfileTracker $tracker,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(PreparationStarted $event): void
    {
        $time = $event->telemetryInfo()->time()->seconds()
            + ($event->telemetryInfo()->time()->nanoseconds() / 1e9);

        $this->tracker->start($event->test()->id(), $time);
    }
}
