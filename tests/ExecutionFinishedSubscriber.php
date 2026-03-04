<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as ExecutionFinishedSubscriberContract;

class ExecutionFinishedSubscriber implements ExecutionFinishedSubscriberContract
{
    private SlowTestTracker $tracker;

    public function __construct(SlowTestTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function notify(ExecutionFinished $event): void
    {
        $slowTests = $this->tracker->getSlowTests();

        if (empty($slowTests)) {
            return;
        }

        echo "\n\n\033[33m Warning：Slow tests detected! \033[0m\n";
        foreach ($slowTests as $test) {
            printf(" ⚠️  %s Consumed \033[33m%.3f seconds\033[0m\n", $test['name'], $test['duration']);
        }
        echo "\n";
    }
}
