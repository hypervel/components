<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Channel;

use Hypervel\Engine\Channel;
use SplQueue;

/**
 * A singleton pool for reusing Channel instances.
 *
 * @extends SplQueue<Channel>
 */
class Pool extends SplQueue
{
    protected static ?Pool $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        return static::$instance ??= new self();
    }

    /**
     * Get a channel from the pool, or create a new one if empty.
     */
    public function get(): Channel
    {
        return $this->isEmpty() ? new Channel(1) : $this->pop();
    }

    /**
     * Release a channel back to the pool.
     */
    public function release(Channel $channel): void
    {
        $channel->errCode = 0;
        $this->push($channel);
    }
}
