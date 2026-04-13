<?php

declare(strict_types=1);

namespace Hypervel\Redis\Events;

use Hypervel\Redis\RedisConnection;

class CommandExecuted
{
    /**
     * The Redis connection name.
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param float $time duration in milliseconds
     */
    public function __construct(
        public string $command,
        public array $parameters,
        public float $time,
        public RedisConnection $connection,
    ) {
        $this->connectionName = $connection->getName();
    }
}
