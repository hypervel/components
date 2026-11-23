<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting\Fixtures;

use Hypervel\Redis\RedisProxy;

// Declare the proxy's dynamic commands so Double can reflect their signatures.
abstract class RedisBroadcasterConnection extends RedisProxy
{
    /**
     * Publish a message to a channel.
     */
    abstract public function publish(string $channel, string $message): int|false;

    /**
     * Evaluate a Lua script.
     */
    abstract public function eval(string $script, int $numberOfKeys, mixed ...$arguments): mixed;
}
