<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Closure;
use Hyperf\Redis\RedisProxy as HyperfRedisProxy;
use Hypervel\Support\Arr;

/**
 * @mixin \Redis
 */
class RedisProxy extends HyperfRedisProxy
{
    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void
    {
        $callback = fn ($redis, $channel, $message) => $callback($message, $channel);

        parent::subscribe(Arr::wrap($channels), $callback);
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void
    {
        $callback = fn ($redis, $pattern, $channel, $message) => $callback($message, $channel);

        parent::psubscribe(Arr::wrap($channels), $callback);
    }
}
