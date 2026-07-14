<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

class AfterEachTestExecutionFinishedSubscriber implements ExecutionFinishedSubscriber
{
    /**
     * Create an execution-finished subscriber.
     */
    public function __construct(
        private readonly AfterEachTestSubscriber $cleanup,
    ) {
    }

    /**
     * Route the execution-finished event to the shared cleanup owner.
     */
    public function notify(ExecutionFinished $event): void
    {
        $this->cleanup->handleExecutionFinished();
    }
}
