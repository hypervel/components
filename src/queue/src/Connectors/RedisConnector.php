<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hyperf\Redis\RedisFactory;
use Hypervel\Queue\Contracts\Queue;
use Hypervel\Queue\RedisQueue;

class RedisConnector implements ConnectorInterface
{
    /**
     * Create a new Redis queue connector instance.
     */
    public function __construct(
        protected RedisFactory $redis,
        protected ?string $connection = null
    ) {
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new RedisQueue(
            $this->redis,
            $config['queue'],
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? false,
            $config['migration_batch_size'] ?? -1
        );
    }
}
