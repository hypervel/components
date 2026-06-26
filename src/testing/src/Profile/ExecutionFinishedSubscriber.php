<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as ExecutionFinishedSubscriberContract;

class ExecutionFinishedSubscriber implements ExecutionFinishedSubscriberContract
{
    /**
     * Create a new execution finished subscriber.
     */
    public function __construct(
        protected ProfileTracker $tracker,
        protected string $directory,
    ) {
    }

    /**
     * Handle the event.
     */
    public function notify(ExecutionFinished $event): void
    {
        $slowTests = $this->tracker->slowTests();

        if ($slowTests === []) {
            return;
        }

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? 'default';
        $path = $this->directory . DIRECTORY_SEPARATOR . 'profile-' . $token . '-' . getmypid() . '.json';

        file_put_contents($path, json_encode($slowTests, JSON_THROW_ON_ERROR));
    }
}
