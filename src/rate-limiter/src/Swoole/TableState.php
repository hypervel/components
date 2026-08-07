<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Swoole;

use Hypervel\Core\Swoole\StripedLock;
use Swoole\Table;

class TableState
{
    /**
     * Create a new Swoole rate limiter table state.
     */
    public function __construct(
        protected string $name,
        protected Table $table,
        protected StripedLock $locks,
    ) {
    }

    /**
     * Get the configured store name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the shared Swoole table.
     */
    public function table(): Table
    {
        return $this->table;
    }

    /**
     * Run the callback while holding the lock for a physical limiter key.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLock(string $key, callable $callback): mixed
    {
        return $this->locks->withLock($key, $callback);
    }
}
