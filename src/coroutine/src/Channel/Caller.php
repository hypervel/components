<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Channel;

use Closure;
use Hypervel\Coroutine\Exception\ChannelClosedException;
use Hypervel\Coroutine\Exception\WaitTimeoutException;
use Hypervel\Engine\Channel;

class Caller
{
    protected ?Channel $channel = null;

    public function __construct(
        protected Closure $closure,
        protected float $waitTimeout = 10,
    ) {
        $this->initInstance();
    }

    /**
     * Execute a closure with the pooled instance.
     */
    public function call(Closure $closure): mixed
    {
        $release = true;
        $channel = $this->channel;
        try {
            $instance = $channel->pop($this->waitTimeout);
            if ($instance === false) {
                if ($channel->isClosing()) {
                    throw new ChannelClosedException('The channel was closed.');
                }

                if ($channel->isTimeout()) {
                    throw new WaitTimeoutException('The instance pop from channel timeout.');
                }
            }

            $result = $closure($instance);
        } catch (ChannelClosedException|WaitTimeoutException $exception) {
            $release = false;
            throw $exception;
        } finally {
            $release && $channel->push($instance ?? null);
        }

        return $result;
    }

    /**
     * Initialize or reinitialize the pooled instance.
     */
    public function initInstance(): void
    {
        $this->channel?->close();
        $this->channel = new Channel(1);
        $this->channel->push($this->closure->__invoke());
    }
}
