<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hyperf\Contract\ConnectionInterface;
use Hypervel\Coroutine\Coroutine;
use Hyperf\Engine\Channel as CoChannel;
use SplQueue;

/**
 * A channel for storing and retrieving pooled connections.
 *
 * Uses a Swoole coroutine channel when in coroutine context,
 * falls back to an SplQueue for non-coroutine environments.
 */
class Channel
{
    protected CoChannel $channel;

    protected SplQueue $queue;

    public function __construct(
        protected int $size
    ) {
        $this->channel = new CoChannel($size);
        $this->queue = new SplQueue();
    }

    /**
     * Pop a connection from the channel.
     */
    public function pop(float $timeout): ConnectionInterface|false
    {
        if ($this->isCoroutine()) {
            return $this->channel->pop($timeout);
        }

        return $this->queue->shift();
    }

    /**
     * Push a connection onto the channel.
     */
    public function push(ConnectionInterface $data): bool
    {
        if ($this->isCoroutine()) {
            return $this->channel->push($data);
        }

        $this->queue->push($data);

        return true;
    }

    /**
     * Get the number of connections in the channel.
     */
    public function length(): int
    {
        if ($this->isCoroutine()) {
            return $this->channel->getLength();
        }

        return $this->queue->count();
    }

    /**
     * Check if currently running in a coroutine.
     */
    protected function isCoroutine(): bool
    {
        return Coroutine::id() > 0;
    }
}
