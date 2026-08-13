<?php

declare(strict_types=1);

namespace Hypervel\Redis\Traits;

use Hypervel\Context\CoroutineContext;
use Hypervel\Redis\RedisConnection;
use Redis;
use RedisCluster;
use Throwable;

/**
 * Coroutine multi-exec trait.
 */
trait MultiExec
{
    /**
     * Execute commands in a pipeline.
     *
     * @return ($callback is null ? Redis : array<int, mixed>|false)
     */
    public function pipeline(?callable $callback = null)
    {
        return $this->executeMultiExec('pipeline', $callback);
    }

    /**
     * Execute commands in a transaction.
     *
     * @return ($callback is null ? Redis|RedisCluster : array<int, mixed>|false)
     */
    public function transaction(?callable $callback = null)
    {
        return $this->executeMultiExec('multi', $callback);
    }

    /**
     * Execute multi-exec commands with optional callback.
     *
     * @return ($callback is null
     *     ? ($command is 'pipeline' ? Redis : Redis|RedisCluster)
     *     : array<int, mixed>|false)
     */
    private function executeMultiExec(string $command, ?callable $callback = null)
    {
        if (is_null($callback)) {
            return $this->__call($command, []);
        }

        $hasExistingConnection = CoroutineContext::has($this->getContextKey());
        $instance = $this->__call($command, []);

        try {
            $result = tap($instance, $callback)->exec();

            if ($command === 'multi' && $hasExistingConnection) {
                $connection = CoroutineContext::get($this->getContextKey());

                if ($connection instanceof RedisConnection) {
                    $connection->clearWatchState();
                }
            }

            return $result;
        } catch (Throwable $exception) {
            $connection = CoroutineContext::get($this->getContextKey());

            if ($connection instanceof RedisConnection) {
                $connection->invalidate();
            }

            throw $exception;
        } finally {
            if (! $hasExistingConnection) {
                $this->releaseContextConnection();
            }
        }
    }
}
