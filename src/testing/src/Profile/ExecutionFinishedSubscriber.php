<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

use Hypervel\Testing\ParallelTesting;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as ExecutionFinishedSubscriberContract;
use RuntimeException;

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

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create profile directory [%s].', $this->directory));
        }

        $token = ParallelTesting::processToken() ?? 'default';
        $path = $this->directory . DIRECTORY_SEPARATOR . 'profile-' . $token . '-' . getmypid() . '.json';
        $encoded = json_encode($slowTests, JSON_THROW_ON_ERROR);
        $written = @file_put_contents($path, $encoded);

        if ($written !== strlen($encoded)) {
            throw new RuntimeException(sprintf('Unable to write test profile [%s].', $path));
        }
    }
}
