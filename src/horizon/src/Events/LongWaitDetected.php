<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Container\Container;
use Hypervel\Horizon\Contracts\LongWaitDetectedNotification;

class LongWaitDetected
{
    /**
     * Create a new event instance.
     *
     * @param string $connection the queue connection name
     * @param string $queue the queue name
     * @param int $seconds the wait time in seconds
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $seconds
    ) {
    }

    /**
     * Get a notification representation of the event.
     */
    public function toNotification(): LongWaitDetectedNotification
    {
        return Container::getInstance()->make(LongWaitDetectedNotification::class, [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'seconds' => $this->seconds,
        ]);
    }
}
