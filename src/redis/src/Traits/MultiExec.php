<?php

declare(strict_types=1);

namespace Hypervel\Redis\Traits;

use Hypervel\Context\Context;
use Hypervel\Redis\Redis as HypervelRedis;
use Redis;
use RedisCluster;

/**
 * Coroutine multi-exec trait.
 *
 * @see Hyperf\Redis\Traits\MultiExec
 */
trait MultiExec
{
    /**
     * Execute commands in a pipeline.
     *
     * @return array|Redis
     */
    public function pipeline(?callable $callback = null)
    {
        return $this->executeMultiExec('pipeline', $callback);
    }

    /**
     * Execute commands in a transaction.
     *
     * @return array|Redis|RedisCluster
     */
    public function transaction(?callable $callback = null)
    {
        return $this->executeMultiExec('multi', $callback);
    }

    /**
     * Execute multi-exec commands with optional callback.
     *
     * @return array|Redis|RedisCluster
     */
    private function executeMultiExec(string $command, ?callable $callback = null)
    {
        if (is_null($callback)) {
            return $this->__call($command, []);
        }

        if (! $this instanceof HypervelRedis) {
            return tap($this->__call($command, []), $callback)->exec();
        }

        $hasExistingConnection = Context::has($this->getContextKey());
        $instance = $this->__call($command, []);

        try {
            return tap($instance, $callback)->exec();
        } finally {
            if (! $hasExistingConnection) {
                $this->releaseContextConnection();
            }
        }
    }
}
