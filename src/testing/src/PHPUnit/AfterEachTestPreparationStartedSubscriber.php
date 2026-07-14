<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

class AfterEachTestPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    /**
     * Create a preparation-started subscriber.
     */
    public function __construct(
        private readonly AfterEachTestSubscriber $cleanup,
    ) {
    }

    /**
     * Route the preparation-started event to the shared cleanup owner.
     */
    public function notify(PreparationStarted $event): void
    {
        $this->cleanup->handlePreparationStarted();
    }
}
