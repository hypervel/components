<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

use Hypervel\Engine\Channel;

class Coordinator
{
    private Channel $channel;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    /**
     * Yield the current coroutine for a given timeout, unless the coordinator is woken up from outside.
     *
     * @return bool Returns true if the coordinator has been woken up
     */
    public function yield(float|int $timeout = -1): bool
    {
        $this->channel->pop((float) $timeout);
        return $this->channel->isClosing();
    }

    /**
     * Determine if the coordinator is closing.
     */
    public function isClosing(): bool
    {
        return $this->channel->isClosing();
    }

    /**
     * Wake up all coroutines yielding for this coordinator.
     */
    public function resume(): void
    {
        $this->channel->close();
    }
}
