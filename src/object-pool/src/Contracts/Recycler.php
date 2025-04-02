<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use DateTime;
use Hyperf\Coordinator\Timer;
use RuntimeException;

interface Recycler
{
    /**
     * Get the time interval for recycling operations.
     */
    public function getInterval(): float;

    /**
     * Set the time interval for recycling operations.
     *
     * @throws RuntimeException if the interval is less than or equal to 0
     */
    public function setInterval(float $interval): void;

    /**
     * Get the timer for scheduling recycle operations.
     */
    public function getTimer(): Timer;

    /**
     * Set the timer for scheduling recycle operations.
     */
    public function setTimer(Timer $timer): void;

    /**
     * Get the ID of the current timer for recycling.
     */
    public function getTimerId(): ?int;

    /**
     * Start objects recycling with the current timer.
     */
    public function start(): void;

    /**
     * Stops automatic recycling of objects in managed pools.
     */
    public function stop(): void;

    /**
     * Gets the timestamp of the last recycling operation for a specific pool.
     */
    public function getLastRecycledAt(string $name): null|DateTime|int;
}
