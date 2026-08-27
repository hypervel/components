<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Connectors;

use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\Connectors\RedisConnector as BaseConnector;

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
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? RedisQueue::DEFAULT_RETRY_AFTER,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? true,
            $config['migration_batch_size'] ?? RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE,
        );
    }
}
