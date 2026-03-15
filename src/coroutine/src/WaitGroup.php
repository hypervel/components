<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use BadMethodCallException;
use Hypervel\Engine\Channel;
use InvalidArgumentException;

/**
 * Go-style WaitGroup for waiting on multiple coroutines.
 *
 * Based on swoole/library implementation.
 */
class WaitGroup
{
    protected Channel $chan;

    protected int $count = 0;

    protected bool $waiting = false;

    public function __construct(int $delta = 0)
    {
        $this->chan = new Channel(1);
        if ($delta > 0) {
            $this->add($delta);
        }
    }

    /**
     * Add to the counter (call before starting coroutines).
     *
     * @throws BadMethodCallException When called concurrently with wait
     * @throws InvalidArgumentException When delta would make counter negative
     */
    public function add(int $delta = 1): void
    {
        if ($this->waiting) {
            throw new BadMethodCallException('WaitGroup misuse: add called concurrently with wait');
        }
        $count = $this->count + $delta;
        if ($count < 0) {
            throw new InvalidArgumentException('WaitGroup misuse: negative counter');
        }
        $this->count = $count;
    }

    /**
     * Decrement the counter (call when a coroutine completes).
     *
     * @throws BadMethodCallException When counter would go negative
     */
    public function done(): void
    {
        $count = $this->count - 1;
        if ($count < 0) {
            throw new BadMethodCallException('WaitGroup misuse: negative counter');
        }
        $this->count = $count;
        if ($count === 0 && $this->waiting) {
            $this->chan->push(true);
        }
    }

    /**
     * Block until the counter reaches zero.
     *
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return bool True if completed, false if timed out
     * @throws BadMethodCallException When wait is called before previous wait returned
     */
    public function wait(float $timeout = -1): bool
    {
        if ($this->waiting) {
            throw new BadMethodCallException('WaitGroup misuse: reused before previous wait has returned');
        }
        if ($this->count > 0) {
            $this->waiting = true;
            $done = $this->chan->pop($timeout);
            $this->waiting = false;
            return $done;
        }
        return true;
    }

    /**
     * Get the current counter value.
     */
    public function count(): int
    {
        return $this->count;
    }
}
