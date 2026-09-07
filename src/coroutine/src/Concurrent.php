<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Coroutine\Exceptions\ChannelClosedException;
use Hypervel\Coroutine\Exceptions\InvalidArgumentException;
use Hypervel\Engine\Channel;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * @method bool isFull()
 * @method bool isEmpty()
 */
class Concurrent
{
    protected Channel $channel;

    public function __construct(
        protected int $limit,
    ) {
        $this->channel = new Channel($limit);
    }

    /**
     * Proxy isFull() and isEmpty() to the channel.
     *
     * @return mixed
     * @throws InvalidArgumentException When method is not supported
     */
    public function __call(string $name, array $arguments)
    {
        if (in_array($name, ['isFull', 'isEmpty'])) {
            return $this->channel->{$name}(...$arguments);
        }

        throw new InvalidArgumentException(sprintf('The method %s is not supported.', $name));
    }

    /**
     * Get the concurrency limit.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the current number of running coroutines.
     */
    public function getRunningCoroutineCount(): int
    {
        return $this->channel->getLength();
    }

    /**
     * Wait until a concurrency slot becomes available.
     *
     * The observed slot is released before this method returns. Another
     * producer may claim it first, in which case create() will wait normally.
     */
    public function waitForAvailableSlot(float $timeout = -1): bool
    {
        if (! $this->channel->push(true, $timeout)) {
            if ($this->channel->isCanceled()) {
                throw new CanceledException('Waiting for a concurrency slot was canceled.');
            }

            if ($this->channel->isClosing()) {
                throw new ChannelClosedException('The concurrency channel is closed.');
            }

            return false;
        }

        // The successful push guarantees this token remains available, so an
        // unbounded pop cannot block and cannot strand capacity after a timeout.
        $this->channel->pop();

        return true;
    }

    /**
     * Create a new coroutine with concurrency limiting.
     */
    public function create(callable $callable): void
    {
        $this->start($callable);
    }

    /**
     * Create a new coroutine with concurrency limiting and parent context propagation.
     *
     * @param array<string> $keys Context keys to copy (empty = all keys)
     */
    public function fork(callable $callable, array $keys = []): void
    {
        $this->start($callable, $keys);
    }

    /**
     * Start a coroutine that owns one concurrency slot.
     *
     * The optional wrapper runs at native child entry. It must not suspend
     * outside the supplied runner and must invoke that runner exactly once.
     *
     * @param array<string>|false $copyContext
     * @param null|Closure(Closure(): void): void $wrapper
     */
    protected function start(callable $callable, array|false $copyContext = false, ?Closure $wrapper = null): void
    {
        $this->acquireSlot();
        $started = false;
        $slotWrapper = function (Closure $run) use (&$started, $wrapper): void {
            try {
                $started = true;

                if ($wrapper === null) {
                    $run();
                } else {
                    $wrapper($run);
                }
            } finally {
                $this->channel->pop();
            }
        };

        try {
            if ($copyContext === false) {
                Coroutine::createOwned($callable, $slotWrapper);
            } else {
                Coroutine::forkOwned($callable, $slotWrapper, $copyContext);
            }
        } catch (Throwable $exception) {
            if (! $started) {
                $this->channel->pop();
            }

            throw $exception;
        }
    }

    /**
     * Acquire one concurrency slot.
     */
    protected function acquireSlot(): void
    {
        if ($this->channel->push(true)) {
            return;
        }

        if ($this->channel->isCanceled()) {
            throw new CanceledException('Waiting for a concurrency slot was canceled.');
        }

        throw new ChannelClosedException('The concurrency channel is closed.');
    }
}
