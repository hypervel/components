<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Closure;
use Hypervel\Reverb\Contracts\Connection;
use LogicException;
use Swoole\Coroutine\Channel;

final class ConnectionLifecycle
{
    /**
     * The lifecycle ownership token.
     */
    private readonly Channel $token;

    /**
     * Whether terminal cleanup owns this lifecycle.
     */
    private bool $closing = false;

    /**
     * The attached Reverb connection.
     */
    private ?Connection $connection = null;

    /**
     * Create a new connection lifecycle.
     */
    public function __construct(
        public readonly int $fd,
    ) {
        $this->token = new Channel(1);
        $this->token->push(true);
    }

    /**
     * Attach the Reverb connection.
     */
    public function attach(Connection $connection): void
    {
        if ($this->connection !== null) {
            throw new LogicException('A Reverb connection is already attached to this lifecycle.');
        }

        $this->connection = $connection;
    }

    /**
     * Get the attached Reverb connection.
     */
    public function connection(): ?Connection
    {
        return $this->connection;
    }

    /**
     * Run an operation while this lifecycle owns the connection.
     */
    public function run(Closure $callback): mixed
    {
        if ($this->token->pop() === false) {
            return null;
        }

        try {
            if ($this->closing) {
                return null;
            }

            return $callback($this);
        } finally {
            $this->token->push(true);
        }
    }

    /**
     * Run terminal cleanup and release all lifecycle waiters.
     */
    public function close(Closure $callback): mixed
    {
        $this->closing = true;

        if ($this->token->pop() === false) {
            return null;
        }

        try {
            return $callback($this);
        } finally {
            $this->token->close();
        }
    }
}
