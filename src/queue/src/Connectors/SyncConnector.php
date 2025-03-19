<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Queue\Contracts\Queue;
use Hypervel\Queue\SyncQueue;

class SyncConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new SyncQueue($config['after_commit'] ?? false);
    }
}
