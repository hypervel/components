<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;

class FakeEventMutex implements EventMutex
{
    /**
     * Attempt to obtain an event mutex.
     */
    public function create(Event $event): bool
    {
        return false;
    }

    /**
     * Determine if an event mutex exists.
     */
    public function exists(Event $event): bool
    {
        return false;
    }

    /**
     * Clear an event mutex.
     */
    public function forget(Event $event): void
    {
    }
}
