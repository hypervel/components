<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Support\Arr;

class DatabaseConnector implements ConnectorInterface
{
    /**
     * Create a new connector instance.
     */
    public function __construct(
        protected ConnectionResolverInterface $connections
    ) {
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new DatabaseQueue(
            $this->connections,
            $config['connection'],
            $config['table'],
            $config['queue'],
            $config['retry_after'],
            Arr::get($config, 'after_commit', false)
        );
    }
}
