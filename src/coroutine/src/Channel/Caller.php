<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Channel;

use Closure;
use Hypervel\Coroutine\Exceptions\ChannelClosedException;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Engine\Channel;
use Swoole\Coroutine\CanceledException;

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
        $channel = $this->channel;
        $entry = $channel->pop($this->waitTimeout);

        if ($entry === false) {
            if ($channel->isCanceled()) {
                throw new CanceledException('Waiting for the pooled instance was canceled.');
            }

            if ($channel->isClosing()) {
                throw new ChannelClosedException('The channel was closed.');
            }

            if ($channel->isTimeout()) {
                throw new WaitTimeoutException('The instance pop from channel timeout.');
            }
        }

        try {
            return $closure($entry['instance']);
        } finally {
            $channel->push($entry);
        }
    }

    /**
     * Initialize or reinitialize the pooled instance.
     */
    public function initInstance(): void
    {
        $instance = $this->closure->__invoke();
        $channel = new Channel(1);
        $channel->push(['instance' => $instance]);

        $previous = $this->channel;
        $this->channel = $channel;
        $previous?->close();
    }
}
