<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Connectors;

use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\Connectors\RedisConnector as BaseConnector;
use Hypervel\Support\Arr;

class RedisConnector extends BaseConnector
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): RedisQueue
    {
        return new RedisQueue(
            $this->redis,
            $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null)
        );
    }
}
