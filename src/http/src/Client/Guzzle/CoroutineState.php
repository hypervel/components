<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\TaskQueue;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Coroutine\Coroutine;
use Throwable;

/**
 * Keep transfers and their cancellation callbacks within their owning coroutine.
 */
class CoroutineState implements NonCopyableContext
{
    protected const string CONTEXT_KEY = '__http.guzzle_state';

    public readonly TaskQueue $queue;

    /** @var array<int, PromiseInterface> */
    protected array $promises = [];

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
     * Retain pending transport work in its owner's context until it settles.
     */
    public function track(PromiseInterface $promise): void
    {
        $identifier = spl_object_id($promise);
        $this->promises[$identifier] = $promise;
        $release = function () use ($identifier): void {
            unset($this->promises[$identifier]);
        };

        $promise->then($release, $release);
    }

    /**
     * Cancel unfinished transport work.
     */
    public function cancel(): void
    {
        foreach ($this->promises as $identifier => $promise) {
            unset($this->promises[$identifier]);
            $promise->cancel();
        }
    }

    /**
     * Finish cancellation and queued callbacks before reporting cleanup failures.
     */
    public function finish(): void
    {
        $failure = null;

        do {
            $this->cancel();

            try {
                $this->queue->run();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        } while ($this->promises !== [] || ! $this->queue->isEmpty());

        if ($failure !== null) {
            throw $failure;
        }
    }
}
