<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\RedisQueue;
use Hypervel\Support\Arr;

class RedisConnector implements ConnectorInterface
{
    /**
     * Create a new Redis queue connector instance.
     */
    public function __construct(
        protected Redis $redis,
        protected ?string $connection = null
    ) {
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        $connection = $config['connection'];

        return new RedisQueue(
            $this->redis,
            $config['queue'],
            $connection ?? $this->connection,
            $config['retry_after'],
            $config['block_for'],
            Arr::get($config, 'after_commit', true),
            Arr::get($config, 'migration_batch_size', RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE)
        );
    }
}
