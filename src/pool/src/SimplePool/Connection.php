<?php

declare(strict_types=1);

namespace Hypervel\Pool\SimplePool;

use Hypervel\Pool\Connection as AbstractConnection;
use Hypervel\Contracts\Container\Container;

/**
 * A simple pooled connection that uses a callback to create connections.
 */
class Connection extends AbstractConnection
{
    /**
     * @var callable
     */
    protected $callback;

    protected mixed $connection = null;

    public function __construct(
        Container $container,
        Pool $pool,
        callable $callback
    ) {
        $this->callback = $callback;

        parent::__construct($container, $pool);
    }

    public function getActiveConnection(): mixed
    {
        if (! $this->connection || ! $this->check()) {
            $this->reconnect();
        }

        return $this->connection;
    }

    public function reconnect(): bool
    {
        $this->connection = ($this->callback)();
        $this->lastUseTime = microtime(true);

        return true;
    }

    public function close(): bool
    {
        $this->connection = null;

        return true;
    }
}
