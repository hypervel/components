<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pool;

interface PoolInterface
{
    /**
     * Get the pool name.
     */
    public function getName(): string;

    /**
     * Get a connection from the connection pool.
     */
    public function get(): ConnectionInterface;

    /**
     * Release a connection back to the connection pool.
     */
    public function release(ConnectionInterface $connection): void;

    /**
     * Discard a borrowed connection from the connection pool.
     */
    public function discard(ConnectionInterface $connection): void;

    /**
     * Close idle connections while the total managed count exceeds the configured minimum.
     */
    public function flush(): void;

    /**
     * Close the connection pool and release its resources.
     */
    public function close(): void;

    /**
     * Determine if the connection pool is closed.
     */
    public function isClosed(): bool;

    /**
     * Get the pool configuration options.
     */
    public function getOption(): PoolOptionInterface;
}
