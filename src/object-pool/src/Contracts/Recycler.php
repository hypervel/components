<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\Coordinator\Timer;
use InvalidArgumentException;

interface Recycler
{
    /**
     * Get the time interval for recycling operations.
     */
    public function getInterval(): float;

    /**
     * Set the time interval for recycling operations.
     *
     * @throws InvalidArgumentException when the interval is not finite and positive
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
     * Stop automatic maintenance of objects in managed pools.
     */
    public function stop(): void;
}
