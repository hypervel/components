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
     * Boot-only. The interval persists on the singleton recycler for the worker
     * lifetime and controls every subsequently scheduled maintenance loop.
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
     *
     * Boot or tests only. Replacing the timer after start would diverge from
     * the already-scheduled loop retained by the previous timer.
     */
    public function setTimer(Timer $timer): void;

    /**
     * Get the ID of the current timer for recycling.
     */
    public function getTimerId(): ?int;

    /**
     * Start objects recycling with the current timer.
     *
     * Boot-only. Starting during a request schedules worker-wide maintenance
     * that affects every subsequently registered pool.
     */
    public function start(): void;

    /**
     * Stop automatic maintenance of objects in managed pools.
     *
     * Boot or tests only. Stopping disables automatic maintenance for every
     * pool in the worker.
     */
    public function stop(): void;
}
