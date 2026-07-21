<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\Exception\InvalidRedisConnectionException;
use Hyperf\Redis\Pool\PoolFactory;
use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use RedisCluster;
use Throwable;

/**
 * @mixin \Hypervel\Redis\RedisConnection
 */
class Redis
{
    protected string $poolName = 'default';

    public function __construct(
        protected PoolFactory $factory
    ) {
    }

    public function __call($name, $arguments)
    {
        $hasContextConnection = Context::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection);

        $start = (float) microtime(true);
        $result = null;
        $exception = null;

        try {
            /** @var RedisConnection $connection */
            $connection = $connection->getConnection();
            $result = $connection->{$name}(...$arguments);
        } catch (Throwable $e) {
            $exception = $e;
        } finally {
            $connection->shouldTransform(false);

            $time = round((microtime(true) - $start) * 1000, 2);
            $connection->getEventDispatcher()?->dispatch(
                new CommandExecuted(
                    $name,
                    $arguments,
                    $time,
                    $connection,
                    $this->poolName,
                    $result,
                    $exception,
                )
            );

            if ($hasContextConnection) {
                // Connection is already in context, don't release
            } elseif ($exception === null && $this->shouldUseSameConnection($name)) {
                // On success with same-connection command: store in context for reuse
                if ($name === 'select' && $db = $arguments[0]) {
                    $connection->setDatabase((int) $db);
                }
                Context::set($this->getContextKey(), $connection);
                if (! $this->isInEagerReleaseMode()) {
                    defer(function () {
                        $this->releaseContextConnection();
                    });
                }
            } else {
                // Release the connection
                $connection->release();
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Execute commands in a pipeline.
     */
    public function pipeline(?callable $callback = null): mixed
    {
        return $this->executeMultiExec('pipeline', $callback);
    }

    /**
     * Execute commands in a transaction.
     */
    public function transaction(?callable $callback = null): mixed
    {
        return $this->executeMultiExec('multi', $callback);
    }

    /**
     * Execute multi-exec commands with an optional callback.
     */
    private function executeMultiExec(string $command, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            return $this->__call($command, []);
        }

        $hasExistingConnection = Context::has($this->getContextKey());

        if (! $hasExistingConnection) {
            $this->enterEagerReleaseMode();
        }

        try {
            /** @var \Redis|RedisCluster $instance */
            $instance = $this->__call($command, []);

            try {
                $callback($instance);
            } catch (Throwable $callbackException) {
                $this->abortMultiExec($instance);

                throw $callbackException;
            }

            return $instance->exec();
        } finally {
            if (! $hasExistingConnection) {
                try {
                    $this->releaseContextConnection();
                } finally {
                    $this->exitEagerReleaseMode();
                }
            }
        }
    }

    /**
     * Abort an open pipeline or transaction without masking the callback error.
     */
    private function abortMultiExec(\Redis|RedisCluster $instance): void
    {
        try {
            if ($instance->discard() !== false) {
                return;
            }
        } catch (Throwable) {
            // Reconnect the wrapper below when native cleanup fails.
        }

        $connection = Context::get($this->getContextKey());

        if (! $connection instanceof RedisConnection) {
            return;
        }

        try {
            if ($connection->reconnect()) {
                return;
            }
        } catch (Throwable) {
            // Closing still prevents a dirty native connection from being reused.
        }

        try {
            $connection->close();
        } catch (Throwable) {
            // Preserve the original callback exception.
        }
    }

    /**
     * Release the connection stored in coroutine context.
     */
    protected function releaseContextConnection(): void
    {
        $contextKey = $this->getContextKey();
        $connection = Context::get($contextKey);

        if ($connection) {
            Context::destroy($contextKey);
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
     * Determine whether the current callback operation should release eagerly.
     */
    private function isInEagerReleaseMode(): bool
    {
        return (bool) Context::get($this->getEagerReleaseContextKey());
    }

    /**
     * Mark the current callback operation for eager release.
     */
    private function enterEagerReleaseMode(): void
    {
        Context::set($this->getEagerReleaseContextKey(), true);
    }

    /**
     * Clear the eager-release marker.
     */
    private function exitEagerReleaseMode(): void
    {
        Context::destroy($this->getEagerReleaseContextKey());
    }

    /**
     * Get the context key used to mark eager-release operations.
     */
    private function getEagerReleaseContextKey(): string
    {
        return sprintf('redis.connection.%s.eager_release', $this->poolName);
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
}
