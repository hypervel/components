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
        /**
         * The maximum size of the channel.
         *
         * @var int
         */
        protected int $size
    ) {
        $this->channel = new CoChannel($size);
        $this->queue = new SplQueue();
    }

    /**
     * Retrieves an object from the channel.
     *
     * @param float $timeout The maximum time to wait for an object
     *
     * @return false|object The retrieved object or false on timeout
     */
    public function pop(float $timeout): false|object
    {
        if ($this->isCoroutine()) {
            return $this->channel->pop($timeout);
        }

        return $this->queue->shift();
    }

    /**
     * Adds an object to the channel.
     *
     * @param object $data The object to add to the channel
     *
     * @return bool Whether the operation was successful
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
     * Gets the current number of objects in the channel.
     *
     * @return int The number of objects in the channel
     */
    public function length(): int
    {
        if ($this->isCoroutine()) {
            return $this->channel->getLength();
        }

        return $this->queue->count();
    }

    /**
     * Determines if the code is running in a coroutine context.
     *
     * @return bool Whether the current execution is in a coroutine
     */
    protected function isCoroutine(): bool
    {
        return Coroutine::id() > 0;
    }
}
