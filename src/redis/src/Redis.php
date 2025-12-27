<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\Exception\InvalidRedisConnectionException;
use Hyperf\Redis\Pool\PoolFactory;
use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Hypervel\Redis\Traits\MultiExec;
use Throwable;

/**
 * @mixin \Hypervel\Redis\RedisConnection
 */
class Redis
{
    use MultiExec;

    protected string $poolName = 'default';

    public function __construct(
        protected PoolFactory $factory
    ) {
    }

    public function __call($name, $arguments)
    {
        $hasContextConnection = Context::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection);

        $hasError = false;
        $start = (float) microtime(true);

        try {
            /** @var RedisConnection $connection */
            $connection = $connection->getConnection();
            $result = $connection->{$name}(...$arguments);
        } catch (Throwable $exception) {
            $hasError = true;
            throw $exception; // @phpstan-ignore finally.exitPoint (bug fixed in fix/redis-exception-swallowing branch)
        } finally {
            $time = round((microtime(true) - $start) * 1000, 2);
            $connection->getEventDispatcher()?->dispatch(
                new CommandExecuted(
                    $name,
                    $arguments,
                    $time,
                    $connection,
                    $this->poolName,
                    $result ?? null,
                    $exception ?? null,
                )
            );

            if ($hasContextConnection) {
                return $hasError ? null : $result; // @phpstan-ignore-line
            }

            // Release connection.
            if ($this->shouldUseSameConnection($name)) {
                if ($name === 'select' && $db = $arguments[0]) {
                    $connection->setDatabase((int) $db);
                }
                // Should storage the connection to coroutine context, then use defer() to release the connection.
                Context::set($this->getContextKey(), $connection);
                defer(function () {
                    $this->releaseContextConnection();
                });

                return $hasError ? null : $result; // @phpstan-ignore-line
            }

            // Release the connection after command executed.
            $connection->release();
        }

        return $result;
    }

    /**
     * Release the connection stored in coroutine context.
     */
    protected function releaseContextConnection(): void
    {
        $contextKey = $this->getContextKey();
        $connection = Context::get($contextKey);

        if ($connection) {
            Context::set($contextKey, null);
            $connection->release();
        }
    }

    /**
     * Define the commands that need same connection to execute.
     * When these commands executed, the connection will storage to coroutine context.
     */
    protected function shouldUseSameConnection(string $methodName): bool
    {
        return in_array($methodName, [
            'multi',
            'pipeline',
            'select',
        ]);
    }

    /**
     * Get a connection from coroutine context, or from redis connection pool.
     */
    protected function getConnection(bool $hasContextConnection): RedisConnection
    {
        $connection = $hasContextConnection
            ? Context::get($this->getContextKey())
            : null;

        $connection = $connection
            ?: $this->factory->getPool($this->poolName)->get();

        if (! $connection instanceof RedisConnection) {
            throw new InvalidRedisConnectionException('The connection is not a valid RedisConnection.');
        }

        return $connection->shouldTransform(true);
    }

    /**
     * The key to identify the connection object in coroutine context.
     */
    protected function getContextKey(): string
    {
        return sprintf('redis.connection.%s', $this->poolName);
    }

    /**
     * Get a Redis connection by name.
     */
    public function connection(string $name = 'default'): RedisProxy
    {
        return ApplicationContext::getContainer()
            ->get(RedisFactory::class)
            ->get($name);
    }

    /**
     * Flush (delete) all Redis keys matching a pattern.
     *
     * Use this for standalone/one-off flush operations. It handles the connection
     * lifecycle automatically (get from pool, flush, release). Uses the default
     * connection, or specify one via Redis::connection($name)->flushByPattern().
     *
     * If you already have a connection (e.g., inside withConnection()), call
     * $connection->flushByPattern() directly to avoid redundant pool operations.
     *
     * Uses SCAN to iterate keys efficiently and deletes them in batches.
     * Correctly handles OPT_PREFIX to avoid the double-prefixing bug.
     *
     * @param string $pattern The pattern to match (e.g., "cache:test:*").
     *                        Should NOT include OPT_PREFIX - it's handled automatically.
     * @return int Number of keys deleted
     */
    public function flushByPattern(string $pattern): int
    {
        $pool = $this->factory->getPool($this->poolName);

        /** @var RedisConnection $connection */
        $connection = $pool->get();

        try {
            return $connection->flushByPattern($pattern);
        } finally {
            $connection->release();
        }
    }
}
