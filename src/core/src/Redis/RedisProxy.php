<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Closure;
use Hyperf\Redis\RedisProxy as HyperfRedisProxy;

class RedisProxy extends HyperfRedisProxy
{
    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void
    {
        $this->getSubscriber()
            ->subscribe($channels, $callback);
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void
    {
        $this->getSubscriber()
            ->psubscribe($channels, $callback);
    }

    protected function getSubscriber(): Subscriber
    {
        $pool = $this->factory->getPool($this->poolName);
        $connection = $pool->get();

        return new Subscriber(
            (fn () => $this->config)->call($connection)  /* @phpstan-ignore-line */
        );
    }
}
