<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Console;

use DateTimeInterface;
use Hypervel\Console\Scheduling\Event;

interface SchedulingMutex
{
    /**
     * Attempt to obtain a scheduling mutex for the given event.
     */
    public function create(Event $event, DateTimeInterface $time): bool;

    /**
     * Determine if a scheduling mutex exists for the given event.
     */
    public function exists(Event $event, DateTimeInterface $time): bool;
}
