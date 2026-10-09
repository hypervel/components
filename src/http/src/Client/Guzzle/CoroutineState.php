<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\TaskQueue;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Coroutine\Coroutine;

/**
 * Keep transfers and their cancellation callbacks within their owning coroutine.
 */
class CoroutineState implements NonCopyableContext
{
    protected const string CONTEXT_KEY = '__http.guzzle_state';

    public readonly TaskQueue $queue;

    /** @var array<int, Promise> */
    protected array $transfers = [];

    /**
     * Create a queue whose lifetime follows its coroutine rather than the process.
     */
    public function __construct()
    {
        $this->queue = new TaskQueue(false);
    }

    /**
     * Get the current coroutine's state without creating it.
     */
    public static function current(): ?self
    {
        return CoroutineContext::get(self::CONTEXT_KEY);
    }

    /**
     * Get the current coroutine's state and register owner-exit cleanup.
     */
    public static function forCurrent(): self
    {
        if (($state = static::current()) !== null) {
            return $state;
        }

        $state = CoroutineContext::set(self::CONTEXT_KEY, new self);

        if (Coroutine::id() >= 0) {
            Coroutine::defer($state->finish(...));
        }

        return $state;
    }

    /**
     * Retain a pending transfer in its owner's context.
     */
    public function add(Promise $transfer): void
    {
        $this->transfers[spl_object_id($transfer)] = $transfer;
    }

    /**
     * Release a settled transfer.
     */
    public function forget(Promise $transfer): void
    {
        unset($this->transfers[spl_object_id($transfer)]);
    }

    /**
     * Cancel unfinished transfers.
     */
    public function cancel(): void
    {
        foreach ($this->transfers as $transfer) {
            $transfer->cancel();
        }
    }

    /**
     * Propagate cancellation before releasing the owner's queue and transfers.
     */
    public function finish(): void
    {
        try {
            do {
                $this->cancel();
                $this->queue->run();
            } while ($this->transfers !== [] || ! $this->queue->isEmpty());
        } finally {
            // Release native transfers if queue processing exits early.
            $this->cancel();
        }
    }
}
