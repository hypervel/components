<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Redis;

use Closure;

interface Connection
{
    /**
     * Register a Redis command listener with the connection.
     */
    public function listen(Closure $callback): void;

    /**
     * Register a Redis command failure listener with the connection.
     */
    public function listenForFailures(Closure $callback): void;

    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void;

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void;

    /**
     * Run a command against the Redis database.
     */
    public function command(string $method, array $parameters = []): mixed;
}
