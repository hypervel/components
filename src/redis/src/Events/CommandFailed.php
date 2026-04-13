<?php

declare(strict_types=1);

namespace Hypervel\Redis\Events;

use Hypervel\Redis\RedisConnection;
use Throwable;

class CommandFailed
{
    /**
     * The Redis connection name.
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param null|float $time duration in milliseconds (Hypervel enhancement — Laravel's CommandFailed does not carry timing)
     */
    public function __construct(
        public string $command,
        public array $parameters,
        public Throwable $exception,
        public RedisConnection $connection,
        public ?float $time = null,
    ) {
        $this->connectionName = $connection->getName();
    }
}
