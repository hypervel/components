<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel as EngineChannel;
use SplQueue;

/**
 * Store idle objects independently of execution mode and signal coroutine waiters.
 *
 * Keep this in sync with the connection-pool channel in `hypervel/pool`.
 */
class Channel
{
    /** @var SplQueue<object> */
    protected SplQueue $queue;

    /** @var EngineChannel<bool> */
    protected EngineChannel $signal;

    protected int $waiters = 0;

    protected bool $closed = false;

    /**
     * Create a pool channel.
     */
    public function __construct(int $size)
    {
        $this->queue = new SplQueue;
        $this->signal = new EngineChannel($size);
    }

    /**
     * Retrieve an idle object without waiting.
     */
    public function pop(): false|object
    {
        return $this->queue->isEmpty() ? false : $this->queue->dequeue();
    }

    /**
     * Push an idle object and wake one waiter.
     */
    public function push(object $data): bool
    {
        $this->queue->enqueue($data);
        $this->signal();

        return true;
    }

    /**
     * Get the current number of objects in the channel.
     */
    public function length(): int
    {
        return $this->queue->count();
    }

    /**
     * Wait for pool state to change.
     */
    public function wait(float $timeout): bool
    {
        if ($this->closed) {
            return true;
        }

        if (! Coroutine::inCoroutine() || $timeout <= 0.0) {
            return false;
        }

        ++$this->waiters;

        try {
            $result = $this->signal->pop($timeout);

            return $result !== false || ! $this->signal->isTimeout();
        } finally {
            --$this->waiters;
        }
    }

    /**
     * Wake one waiter after a capacity-relevant state change.
     */
    public function signal(): void
    {
        if ($this->closed || $this->waiters === 0) {
            return;
        }

        if (Coroutine::inCoroutine()) {
            $this->pushSignal();

            return;
        }

        Coroutine::create($this->pushSignal(...));
    }

    /**
     * Push a coalesced wake signal without blocking on a full channel.
     */
    protected function pushSignal(): void
    {
        if (! $this->closed && ! $this->signal->isFull()) {
            $this->signal->push(true);
        }
    }

    /**
     * Close the signal channel and wake every waiter.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->signal->close();
    }
}
