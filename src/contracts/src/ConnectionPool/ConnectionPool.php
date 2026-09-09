<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ConnectionPool;

use Hypervel\ConnectionPool\PoolOptions;

interface ConnectionPool
{
    /**
     * Get the pool name.
     */
    public function getName(): string;

    /**
     * Borrow a connection from the pool.
     */
    public function borrow(): Connection;

    /**
     * Release a connection back to the connection pool.
     */
    public function release(Connection $connection): void;

    /**
     * Discard a borrowed connection from the connection pool.
     */
    public function discard(Connection $connection): void;

    /**
     * Close excess idle connections without checking their age.
     *
     * Stop when the managed count reaches the retained minimum or no idle connections remain.
     */
    public function trimExcessIdle(): void;

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
    public function getOptions(): PoolOptions;

    /**
     * Return the number of connections managed by the pool.
     */
    public function getManagedCount(): int;

    /**
     * Return the number of connections borrowed by callers.
     */
    public function getBorrowedCount(): int;

    /**
     * Return the number of idle connections available for borrowing.
     */
    public function getIdleCount(): int;

    /**
     * Return the number of coroutines waiting for capacity.
     */
    public function getWaitingCount(): int;

    /**
     * Return the pool's current resource counts and closed state.
     *
     * @return array{managed: int, borrowed: int, idle: int, waiting: int, closed: bool}
     */
    public function getStats(): array;
}
