<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Container\Container;
use Hypervel\Horizon\Contracts\LongWaitDetectedNotification;

class LongWaitDetected
{
    /**
     * The queue connection name.
     */
    public string $connection;

    /**
     * The queue name.
     */
    public string $queue;

    /**
     * The wait time in seconds.
     */
    public int $seconds;

    /**
     * Create a new event instance.
     */
    public function __construct(string $connection, string $queue, int $seconds)
    {
        $this->queue = $queue;
        $this->seconds = $seconds;
        $this->connection = $connection;
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
