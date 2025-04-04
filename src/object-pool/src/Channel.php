<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel as CoChannel;
use SplQueue;

class Channel
{
    protected CoChannel $channel;

    protected SplQueue $queue;

    public function __construct(protected int $size)
    {
        $this->channel = new CoChannel($size);
        $this->queue = new SplQueue();
    }

    public function pop(float $timeout): false|object
    {
        if ($this->isCoroutine()) {
            return $this->channel->pop($timeout);
        }
        return $this->queue->shift();
    }

    public function push(object $data): bool
    {
        if ($this->isCoroutine()) {
            return $this->channel->push($data);
        }
        $this->queue->push($data);
        return true;
    }

    public function length(): int
    {
        if ($this->isCoroutine()) {
            return $this->channel->getLength();
        }
        return $this->queue->count();
    }

    protected function isCoroutine(): bool
    {
        return Coroutine::id() > 0;
    }
}
