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
        $connection = $config['connection'];

        return new RedisQueue(
            $this->redis,
            $config['queue'],
            $connection ?? $this->connection,
            $config['retry_after'],
            $config['block_for'],
            $config['after_commit'],
            $config['migration_batch_size'],
        );
    }
}
