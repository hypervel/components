<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as ExecutionFinishedSubscriberContract;

class ExecutionFinishedSubscriber implements ExecutionFinishedSubscriberContract
{
    /**
     * Create a new execution finished subscriber.
     */
    public function __construct(
        private SlowTestTracker $tracker,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(ExecutionFinished $event): void
    {
        $slowTests = $this->tracker->getSlowTests();

        if (empty($slowTests)) {
            return;
        }

        echo "\n\n\033[33m Warning: Slow tests detected! \033[0m\n";
        foreach ($slowTests as $test) {
            printf(" ⚠️  %s Consumed \033[33m%.3f seconds\033[0m\n", $test['name'], $test['duration']);
        }
        echo "\n";
    }
}
