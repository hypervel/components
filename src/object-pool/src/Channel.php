<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel as CoChannel;
use SplQueue;

class Channel
{
    /**
     * Coroutine channel for handling objects in coroutine mode.
     */
    protected CoChannel $channel;

    /**
     * Queue for handling objects in non-coroutine mode.
     */
    protected SplQueue $queue;

    /**
     * Constructor for Channel.
     *
     * @param int $size The maximum size of the channel
     */
    public function __construct(
        protected int $size
    ) {
        $this->channel = new CoChannel($size);
        $this->queue = new SplQueue();
    }

    /**
     * Retrieve an object from the channel.
     */
    public function pop(float $timeout): false|object
    {
        if ($this->isCoroutine()) {
            return $this->channel->pop($timeout);
        }

        return $this->queue->shift();
    }

    /**
     * Push an object to the channel.
     */
    public function push(object $data): bool
    {
        if ($this->isCoroutine()) {
            return $this->channel->push($data);
        }
        $this->queue->push($data);

        return true;
    }

    /**
     * Get the current number of objects in the channel.
     */
    public function length(): int
    {
        if ($this->isCoroutine()) {
            return $this->channel->getLength();
        }

        return $this->queue->count();
    }

    /**
     * Determine if the code is running in a coroutine context.
     */
    protected function isCoroutine(): bool
    {
        return Coroutine::id() > 0;
    }
}
